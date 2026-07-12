<?php

namespace App\Actions\Agent;

use App\Actions\Outages\RecordOutage;
use App\Enums\DeviceStatus;
use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\Agent;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\Polling\RateCalculator;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * Fold a remote agent's poll results into the same pipeline central polling uses:
 * up/down status (-> DeviceStatusChanged + outage record) and interface throughput
 * (bps/util + recent-history sample + the coalesced InterfaceUtilUpdated broadcast the
 * live map colour ramp reads).
 *
 * Security: an agent may only report for devices/interfaces assigned to IT. Anything in
 * the payload that isn't the agent's is ignored - a compromised or buggy agent can't move
 * another site's data.
 *
 * The agent computes bps itself (it holds consecutive counters across its own polls, with
 * the counter-reset guard); this action computes util against the interface's known speed
 * (RateCalculator::utilPercent) so the util/colour authority stays central.
 *
 * @phpstan-type PingResult array{device_id:int, up:bool}
 * @phpstan-type FlowResult array{interface_id:int, in_bps:float, out_bps:float}
 */
class IngestAgentResults
{
    public function __construct(
        private RecordOutage $outages,
        private RateCalculator $rates,
    ) {}

    /** @param array<string,mixed> $payload */
    public function __invoke(Agent $agent, array $payload): void
    {
        $this->ingestPings($agent, $payload['pings'] ?? []);
        $this->ingestThroughput($agent, $payload['throughput'] ?? []);
    }

    /** @param array<int,array{device_id:int,up:bool}> $pings */
    private function ingestPings(Agent $agent, array $pings): void
    {
        if ($pings === []) {
            return;
        }
        // Only this agent's devices, indexed by id.
        $wanted = collect($pings)->pluck('device_id')->all();
        $devices = Device::where('agent_id', $agent->id)->whereIn('id', $wanted)->get()->keyBy('id');

        foreach ($pings as $p) {
            $device = $devices->get($p['device_id'] ?? 0);
            if ($device === null) {
                continue; // not this agent's device - ignore
            }
            $new = ($p['up'] ?? false) ? DeviceStatus::Up : DeviceStatus::Down;
            if ($device->status === $new) {
                continue; // change-only, like the central sweep
            }
            $device->status = $new;
            $device->last_change = now();
            $device->save();
            DeviceStatusChanged::dispatch($device);
            $new === DeviceStatus::Down ? $this->outages->open($device) : $this->outages->close($device);
        }
    }

    /** @param array<int,array{interface_id:int,in_bps:float,out_bps:float}> $flows */
    private function ingestThroughput(Agent $agent, array $flows): void
    {
        if ($flows === []) {
            return;
        }
        $wanted = collect($flows)->pluck('interface_id')->all();
        // Interfaces on this agent's devices only (join enforces ownership).
        $ifaces = NetworkInterface::whereIn('interfaces.id', $wanted)
            ->whereIn('device_id', Device::where('agent_id', $agent->id)->select('id'))
            ->get()->keyBy('id');

        $now = now();
        $frames = [];      // device_id => interface frames
        $sampleRows = [];

        foreach ($flows as $f) {
            $iface = $ifaces->get($f['interface_id'] ?? 0);
            if ($iface === null) {
                continue;
            }
            $inBps = (int) round((float) ($f['in_bps'] ?? 0));
            $outBps = (int) round((float) ($f['out_bps'] ?? 0));
            // Interface speed is symmetric (asymmetry moved to the link, FR-28); the link's
            // effective speed drives the colour ramp separately. Util here is vs the port speed.
            $utilIn = $this->rates->utilPercent($inBps, $iface->speed_mbps);
            $utilOut = $this->rates->utilPercent($outBps, $iface->speed_mbps);

            DB::table('interfaces')->where('id', $iface->id)->update([
                'bps_in' => $inBps, 'bps_out' => $outBps,
                'util_in' => $utilIn, 'util_out' => $utilOut,
                'last_ts' => $now, 'updated_at' => $now,
            ]);
            $sampleRows[] = [
                'interface_id' => $iface->id, 'ts' => $now,
                'bps_in' => $inBps, 'bps_out' => $outBps, 'util_in' => $utilIn, 'util_out' => $utilOut,
            ];
            $frames[$iface->device_id][] = [
                'interface_id' => $iface->id, 'util_in' => $utilIn, 'util_out' => $utilOut,
                'speed_mbps' => $iface->speed_mbps, 'bps_in' => $inBps, 'bps_out' => $outBps, 'status' => 'up',
            ];
        }

        $this->recordHistory($sampleRows);

        if ($frames !== []) {
            $devices = [];
            foreach ($frames as $deviceId => $ifaceFrames) {
                $devices[] = ['device_id' => $deviceId, 'status' => DeviceStatus::Up->value, 'interfaces' => $ifaceFrames];
            }
            InterfaceUtilUpdated::dispatch($devices);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function recordHistory(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        try {
            DB::table('interface_samples')->insert($rows);
        } catch (\Throwable $e) {
            EngineLog::warning('agent: history insert failed', ['error' => $e->getMessage()]);
        }
    }
}
