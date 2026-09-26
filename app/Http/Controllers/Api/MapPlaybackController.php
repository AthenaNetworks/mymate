<?php

namespace App\Http\Controllers\Api;

use App\Actions\History\GetMapPlayback;
use App\Actions\History\HistoryTiers;
use App\Http\Controllers\Controller;
use App\Models\Map;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Historical playback for the geo map (GitHub #22).
 *
 * GET /api/maps/{map}/playback?from=&to=&points=   a series of frames over [from, to)
 *     &offset=&limit=                              only load the series for some of the frames
 * GET /api/maps/{map}/playback?at=                 one frame, the network at that moment
 *
 * {map} binds through the Map visibility scope, so a map a restricted operator hasn't been
 * granted 404s, and GetMapPlayback reads devices through the Device scope. `to` defaults to now
 * and `from` to 24h before it. See GetMapPlayback for the payload.
 */
class MapPlaybackController extends Controller
{
    public function __invoke(Request $request, Map $map, GetMapPlayback $playback, HistoryTiers $tiers): JsonResponse
    {
        $v = $request->validate([
            'at' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'points' => ['nullable', 'integer', 'min:2', 'max:'.GetMapPlayback::MAX_FRAMES],
            'offset' => ['nullable', 'integer', 'min:0', 'max:'.GetMapPlayback::MAX_FRAMES],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.GetMapPlayback::MAX_FRAMES],
        ]);

        $now = now();
        $oldest = $now->copy()->subDays($tiers->maxDays());

        if (isset($v['at'])) {
            $at = Carbon::parse($v['at']);
            if ($at->greaterThan($now) || $at->lessThan($oldest)) {
                throw ValidationException::withMessages(['at' => 'That moment is outside the history that is kept.']);
            }

            return response()->json(['data' => $playback->at($map, $at)]);
        }

        $to = isset($v['to']) ? Carbon::parse($v['to'])->min($now) : $now->copy();
        $from = isset($v['from']) ? Carbon::parse($v['from']) : $to->copy()->subDay();
        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages(['from' => 'The start has to be before the end.']);
        }
        // nothing is kept further back than the longest tier, so don't plan a window over it
        $from = $from->max($oldest);

        return response()->json(['data' => $playback->range(
            $map, $from, $to,
            isset($v['points']) ? (int) $v['points'] : null,
            (int) ($v['offset'] ?? 0),
            isset($v['limit']) ? (int) $v['limit'] : null,
        )]);
    }
}
