<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Jobs\Tools\RunPingJob;
use App\Jobs\Tools\RunPortScanJob;
use App\Jobs\Tools\RunSweepJob;
use App\Jobs\Tools\RunToolTraceJob;
use App\Services\Tools\BgpToolsClient;
use App\Services\Tools\ServiceNames;
use App\Services\Tools\Targets;
use App\Services\Tools\ToolRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Tools page backend. Ping / trace / sweep / port scan each start a streaming job
 * (RunPingJob et al.) that writes into the shared tool-run cache; this controller only
 * starts, reads and stops those runs - no run state touches the database. The bgp.tools
 * lookup is the one synchronous tool: it proxies their whois and returns the parsed row.
 *
 * All of these are operator-safe diagnostics (see RestrictWritesToAdmins' exemption list):
 * they read the network, they don't mutate the monitored fleet. Every run records the user
 * that started it so one operator can't cancel another's live run.
 */
class ToolsController extends Controller
{
    public function startPing(Request $request): JsonResponse
    {
        $target = $this->validTarget($request);
        $count = max(1, min(1000, (int) $request->input('count', 20)));

        return $this->launch('ping', $target, $request, ['sent' => 0, 'recv' => 0, 'probes' => []], function (string $runId) use ($target, $count) {
            RunPingJob::dispatch($runId, $target, $count);
        });
    }

    public function startTrace(Request $request): JsonResponse
    {
        $target = $this->validTarget($request);
        $rounds = max(5, min(120, (int) $request->input('rounds', 30)));

        return $this->launch('trace', $target, $request, ['rounds_total' => $rounds, 'rounds_done' => 0, 'hops' => []], function (string $runId) use ($target, $rounds) {
            RunToolTraceJob::dispatch($runId, $target, $rounds);
        });
    }

    public function startPortScan(Request $request): JsonResponse
    {
        $target = $this->validTarget($request);
        $ports = $this->validPorts($request) ?? ServiceNames::COMMON_PORTS;

        return $this->launch('portscan', $target, $request, ['total' => count($ports), 'scanned' => 0, 'open' => []], function (string $runId) use ($target, $ports) {
            RunPortScanJob::dispatch($runId, $target, $ports);
        });
    }

    public function startSweep(Request $request): JsonResponse
    {
        $cidr = trim((string) $request->input('cidr', ''));
        $cap = (int) config('mymate.tools.max_sweep_hosts', 1024);
        if (! Targets::isCidr($cidr)) {
            throw ValidationException::withMessages(['cidr' => 'Enter an IPv4 subnet in CIDR form, e.g. 192.168.1.0/24.']);
        }
        if (Targets::hostCount($cidr) > $cap) {
            throw ValidationException::withMessages(['cidr' => "That subnet is too large to sweep (limit {$cap} hosts). Use a smaller prefix."]);
        }

        // Port scanning each live host is opt-in; when on, use the caller's ports or the common set.
        $ports = $request->boolean('scan_ports')
            ? ($this->validPorts($request) ?? ServiceNames::COMMON_PORTS)
            : [];

        return $this->launch('sweep', $cidr, $request, ['total' => Targets::hostCount($cidr), 'phase' => 'discovering', 'alive' => 0, 'hosts' => []], function (string $runId) use ($cidr, $ports) {
            RunSweepJob::dispatch($runId, $cidr, $ports);
        });
    }

    /** Current snapshot for any run. 404 on an unknown/expired id. */
    public function show(string $runId): JsonResponse
    {
        $snapshot = ToolRun::get($runId);
        abort_if($snapshot === null, 404);

        return response()->json($snapshot);
    }

    /** Flag a run to stop. Only its starter (or an admin) may cancel it. */
    public function stop(Request $request, string $runId): Response
    {
        abort_if(ToolRun::get($runId) === null, 404);

        $owner = ToolRun::owner($runId);
        abort_unless($request->user()->isAdmin() || ($owner !== null && $owner === $request->user()->id), 403);

        ToolRun::requestStop($runId);

        return response()->noContent();
    }

    /** Synchronous bgp.tools lookup for an IP or ASN. Cached server-side (see BgpToolsClient). */
    public function bgp(Request $request, BgpToolsClient $client): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));
        if ($query === '') {
            throw ValidationException::withMessages(['query' => 'Enter an IP address or ASN to look up.']);
        }

        $result = $client->lookup($query);
        if ($result === null) {
            return response()->json(['message' => 'Not a valid IP/ASN, or bgp.tools did not answer in time.'], 422);
        }

        return response()->json($result);
    }

    /**
     * Seed the run's cache, record the owner, dispatch the job, and hand back the id to poll.
     * Seeding before dispatch means the frontend's first poll is a 200, not a 404 race.
     *
     * @param  array<string, mixed>  $initial
     * @param  callable(string): void  $dispatch
     */
    private function launch(string $kind, string $target, Request $request, array $initial, callable $dispatch): JsonResponse
    {
        $runId = (string) Str::uuid();
        ToolRun::start($runId, $kind, $target, $request->user()->id, $initial);
        $dispatch($runId);

        return response()->json(['run_id' => $runId, 'kind' => $kind, 'status' => 'running'], 202);
    }

    private function validTarget(Request $request): string
    {
        $target = trim((string) $request->input('target', ''));
        if (! Targets::isHost($target)) {
            throw ValidationException::withMessages(['target' => 'Enter a valid IP address or hostname.']);
        }

        return $target;
    }

    /**
     * A caller-supplied port list, or null when none was given (the caller then falls back to
     * the common set). Accepts an array or a comma/space separated string; dedupes and caps.
     *
     * @return list<int>|null
     */
    private function validPorts(Request $request): ?array
    {
        $input = $request->input('ports');
        if ($input === null || $input === '' || $input === []) {
            return null;
        }

        $raw = is_array($input) ? $input : preg_split('/[\s,]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY);

        $ports = [];
        foreach ($raw as $p) {
            $n = (int) $p;
            if ($n >= 1 && $n <= 65535) {
                $ports[$n] = $n; // key-dedupe
            }
        }
        $ports = array_values($ports);

        if ($ports === []) {
            throw ValidationException::withMessages(['ports' => 'Ports must be numbers between 1 and 65535.']);
        }

        $max = (int) config('mymate.tools.max_ports', 1024);
        if (count($ports) > $max) {
            throw ValidationException::withMessages(['ports' => "Too many ports (limit {$max})."]);
        }

        return $ports;
    }
}
