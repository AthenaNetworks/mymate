<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\GetDeviceEvents;
use App\Actions\History\GetDeviceHistory;
use App\Actions\History\GetPortBilling;
use App\Actions\History\HistoryCatalog;
use App\Actions\History\HistoryFamilies;
use App\Actions\History\HistoryTiers;
use App\Http\Controllers\Controller;
use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\Map;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The full device page (GitHub #28): its history catalog, the one generic history read every
 * graph on it uses, 95th percentile billing for its ports, the event timeline and a few extra
 * facts for the Overview header.
 *
 * Everything hangs off the {device} binding, so the Device visibility scope has already 404'd a
 * device a restricted operator can't see before any of this runs. Keys (ports, probes...) are
 * checked against the device by HistoryCatalog, so another device's rows can't be read through
 * a visible one.
 */
class DeviceHistoryController extends Controller
{
    /** GET /api/devices/{device}/history/catalog - what can be graphed for this device. */
    public function catalog(Device $device, HistoryCatalog $catalog, HistoryTiers $tiers): JsonResponse
    {
        return response()->json(['data' => [
            'device_id' => $device->id,
            'families' => $catalog->forDevice($device),
            'retention_days' => [
                'raw' => $tiers->retentionDays('raw'),
                '5m' => $tiers->retentionDays('5m'),
                '1h' => $tiers->retentionDays('1h'),
            ],
            'max_days' => $tiers->maxDays(),
        ]]);
    }

    /**
     * GET /api/devices/{device}/history?family=&metrics[]=&keys[]=&from=&to=&points=&aggregate=&p95=
     * One family's series on the tier-aware grid, with legend stats.
     */
    public function history(Request $request, Device $device, GetDeviceHistory $get, HistoryTiers $tiers): JsonResponse
    {
        $v = $request->validate([
            'family' => ['required', 'string', Rule::in(array_keys(HistoryFamilies::FAMILIES))],
            'metrics' => ['sometimes', 'array', 'max:20'],
            'metrics.*' => ['string'],
            'keys' => ['sometimes', 'array', 'max:200'],
            'keys.*' => ['string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'points' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'aggregate' => ['sometimes', 'boolean'],
            'p95' => ['sometimes', 'boolean'],
        ]);

        $known = array_keys(HistoryFamilies::get($v['family'])['metrics']);
        $metrics = array_values($v['metrics'] ?? []);
        if ($unknown = array_diff($metrics, $known)) {
            return response()->json(['message' => 'Unknown metric: '.implode(', ', $unknown)], 422);
        }

        [$from, $to] = $this->window($v, $tiers);
        $data = $get(
            $device, $v['family'], $metrics, array_values($v['keys'] ?? []), $from, $to,
            isset($v['points']) ? (int) $v['points'] : null, (bool) ($v['aggregate'] ?? false), (bool) ($v['p95'] ?? false),
        );

        return $data === null
            ? response()->json(['message' => 'That key does not belong to this device.'], 422)
            : response()->json(['data' => $data]);
    }

    /**
     * GET /api/devices/{device}/billing?period=this_month|last_month|custom&from=&to=&keys[]=&aggregate=
     * 95th percentile and transfer per port, plus the sum of the selected ports.
     */
    public function billing(Request $request, Device $device, GetPortBilling $get): JsonResponse
    {
        $v = $request->validate([
            'period' => ['sometimes', Rule::in(['this_month', 'last_month', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['nullable', 'date'],
            'keys' => ['sometimes', 'array', 'max:200'],
            'keys.*' => ['string', 'max:50'],
            'aggregate' => ['sometimes', 'boolean'],
        ]);

        $period = $v['period'] ?? 'this_month';
        [$from, $to, $label] = GetPortBilling::period(
            $period,
            isset($v['from']) ? $this->parse($v['from']) : null,
            isset($v['to']) ? $this->parse($v['to']) : null,
        );
        if ($to->lessThanOrEqualTo($from)) {
            return response()->json(['message' => 'The period has to end after it starts.'], 422);
        }
        if ($from->diffInDays($to, true) > 400) {
            return response()->json(['message' => 'Billing periods are limited to 400 days.'], 422);
        }

        $keys = array_values($v['keys'] ?? []);
        $data = $get($device, $keys, $from, $to, (bool) ($v['aggregate'] ?? count($keys) !== 1));
        if ($data === null) {
            return response()->json(['message' => 'That port does not belong to this device.'], 422);
        }

        return response()->json(['data' => ['period' => $period, 'label' => $label, ...$data]]);
    }

    /** GET /api/devices/{device}/events?page=&per_page=&types[]= - the merged timeline. */
    public function events(Request $request, Device $device, GetDeviceEvents $get): JsonResponse
    {
        $v = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:200'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(['outage', 'alert', 'backup', 'upgrade', 'reboot'])],
        ]);

        // config backups are off limits to restricted operators (see RestrictedAccess)
        $withBackups = ! (bool) $request->user()?->isRestricted();

        return response()->json($get($device, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 50), array_values($v['types'] ?? []), $withBackups));
    }

    /**
     * GET /api/devices/{device}/summary - facts the Overview needs that the device resource
     * doesn't carry: the maps it's on (only ones the viewer can see), its agent, the alerts
     * open against it and its last firmware upgrade.
     */
    public function summary(Device $device): JsonResponse
    {
        $device->loadMissing('agent:id,name,status');
        $maps = Map::whereIn('id', $device->mapPositions()->select('map_id'))->orderBy('name')->get(['id', 'name']);

        $alerts = AlertEvent::with('policy:id,name')
            ->where(fn ($q) => $q->where('dedupe_key', "device:{$device->id}")->orWhere('dedupe_key', 'like', "device:{$device->id}:%"))
            ->whereIn('status', ['firing', 'resolving'])
            ->latest('fired_at')->limit(50)->get();

        $upgrade = $device->upgrades()->latest('id')->first();
        $upgradeBy = $upgrade?->user_id !== null ? User::whereKey($upgrade->user_id)->value('name') : null;

        return response()->json(['data' => [
            'last_upgrade' => $upgrade !== null ? GetDeviceEvents::upgradeEvent($upgrade, $upgradeBy) : null,
            'maps' => $maps->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'agent' => $device->agent ? ['id' => $device->agent->id, 'name' => $device->agent->name, 'status' => $device->agent->status] : null,
            'interfaces' => $device->interfaces()->count(),
            'open_alerts' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'status' => $a->status,
                'policy_name' => $a->policy?->name,
                'message' => $a->message,
                'fired_at' => $a->fired_at?->toIso8601ZuluString(),
                'acknowledged' => $a->acknowledged_at !== null,
            ])->values(),
        ]]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(array $v, HistoryTiers $tiers): array
    {
        $to = isset($v['to']) ? $this->parse($v['to']) : now();
        $from = isset($v['from']) ? $this->parse($v['from']) : $to->copy()->subSeconds((int) config('mymate.history.default_window', 3600));
        // nothing older than the longest tier exists, so don't plan a grid across empty years
        $floor = now()->subDays($tiers->maxDays() + 1);
        if ($from->lessThan($floor)) {
            $from = $floor;
        }
        if ($from->greaterThanOrEqualTo($to)) {
            $from = $to->copy()->subMinute();
        }

        return [$from, $to];
    }

    /** Samples are stored in app time, so bring an offset timestamp from the browser onto it. */
    private function parse(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
