<?php

namespace App\Http\Controllers\Api;

use App\Actions\History\GetGraphData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGraphRequest;
use App\Http\Resources\GraphResource;
use App\Models\Graph;
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
    public function data(Request $request, Graph $graph, GetGraphData $get): JsonResponse
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

        $result = $get($configSeries, $metric, ! empty($config['show_total']), $from, $to);

        return response()->json(['data' => [
            'buckets' => $result['buckets'],
            'metric' => $metric,
            'series' => $result['series'],
            'total' => $result['total'],
        ]]);
    }
}
