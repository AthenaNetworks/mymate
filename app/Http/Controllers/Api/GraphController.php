<?php

namespace App\Http\Controllers\Api;

use App\Actions\History\GetGraphSeries;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGraphRequest;
use App\Http\Resources\GraphResource;
use App\Models\Graph;
use App\Models\NetworkInterface;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Custom graph CRUD plus the multi-interface time series that backs one (GitHub #28). */
class GraphController extends Controller
{
    /** Named time ranges -> seconds back from now. Capped by history retention. */
    private const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function index(): AnonymousResourceCollection
    {
        return GraphResource::collection(Graph::orderBy('name')->get());
    }

    public function store(StoreGraphRequest $request): JsonResponse
    {
        $graph = Graph::create($request->validated());

        return (new GraphResource($graph))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(StoreGraphRequest $request, Graph $graph): GraphResource
    {
        $graph->update($request->validated());

        return new GraphResource($graph);
    }

    public function destroy(Graph $graph): Response
    {
        $graph->delete();

        return response()->noContent();
    }

    /** The aligned per-series (and optional total) data for one graph over the chosen range. */
    public function data(Request $request, Graph $graph, GetGraphSeries $get): JsonResponse
    {
        $seconds = self::RANGES[$request->query('range', '24h')] ?? 86400;
        // Don't ask for more history than we keep, or the chart just shows empty leading space.
        $retentionDays = max(1, app(Settings::class)->getInt('history.retention_days', 14));
        $seconds = min($seconds, $retentionDays * 86400);

        $to = now();
        $from = $to->copy()->subSeconds($seconds);

        $config = $graph->config ?? [];
        $metric = ($config['metric'] ?? 'rate') === 'util' ? 'util' : 'rate';
        $configSeries = is_array($config['series'] ?? null) ? $config['series'] : [];

        $interfaceIds = array_values(array_unique(array_map(static fn ($s) => (int) ($s['interface_id'] ?? 0), $configSeries)));
        $names = NetworkInterface::whereIn('id', $interfaceIds)->with('device:id,name')
            ->get(['id', 'device_id', 'name'])->keyBy('id');

        $result = $get($interfaceIds, $from, $to);
        $bucketCount = count($result['buckets']);

        $series = [];
        foreach ($configSeries as $s) {
            $id = (int) ($s['interface_id'] ?? 0);
            $direction = ($s['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $iface = $names->get($id);
            if ($iface === null || ! isset($result['interfaces'][$id])) {
                continue; // interface deleted since the graph was saved
            }
            $key = ($metric === 'util' ? 'util' : 'bps')."_{$direction}"; // bps_in | bps_out | util_in | util_out
            $series[] = [
                'interface_id' => $id,
                'direction' => $direction,
                'device_name' => $iface->device?->name,
                'interface_name' => $iface->name,
                'values' => $result['interfaces'][$id][$key],
            ];
        }

        $total = ! empty($config['show_total']) && $series !== []
            ? self::sumSeries(array_map(static fn ($s) => $s['values'], $series), $bucketCount)
            : null;

        return response()->json(['data' => [
            'buckets' => $result['buckets'],
            'metric' => $metric,
            'series' => $series,
            'total' => $total,
        ]]);
    }

    /**
     * Elementwise sum across series, null-aware: a bucket is null only when every series is null
     * there (no data), otherwise it sums the ones that have a value.
     *
     * @param  list<list<?float>>  $seriesValues
     * @return list<?float>
     */
    private static function sumSeries(array $seriesValues, int $bucketCount): array
    {
        $out = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $sum = null;
            foreach ($seriesValues as $values) {
                $v = $values[$i] ?? null;
                if ($v !== null) {
                    $sum = ($sum ?? 0) + $v;
                }
            }
            $out[] = $sum;
        }

        return $out;
    }
}
