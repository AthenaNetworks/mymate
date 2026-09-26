<?php

namespace App\Actions\History;

use App\Models\Link;
use App\Models\Map;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Historical playback for one map (GitHub #22): what every device and link on it looked like
 * over a window, as a series of frames the geo and logical maps can scrub through.
 *
 * Everything is aligned to one time axis (`frames`, unix seconds, one per bucket start) and each
 * series is a plain array with one value per frame, null where there was no sample, rather than an
 * object per point. Even so a 2000 device map over a day is ~2.5M numbers, so the series come in
 * chunks of frames (offset/limit) and the client pages through them.
 *
 *  - interfaces: bps in/out per interface on a link between two of the map's devices, the same
 *    raw signal the live map colours links from. Util is left to the client, which runs it through
 *    the same computeData as live mode with the link's effective speeds, so colours match exactly.
 *  - devices:    ping rtt/loss per device, averaged over the frame.
 *  - health:     cpu_pct / mem_used_pct / temp_c per device from the device_metric family, the
 *                same chunked shape. Only the metrics a device actually reported in the chunk are
 *                there (a switch with no temp sensor has no temp_c array), and a device that
 *                reported none at all is left out, so the ping-only majority of a big map costs
 *                nothing.
 *  - down:       per device, the frame indexes it was down in, from the outages table (exact
 *                down/up times, so a 40 second blip still shows on a 5 minute frame).
 *  - outages:    the outages themselves, [device_id, start, end|null], for markers on the scrubber.
 *
 * The tier and bucket width are picked like the graphs do (HistoryTiers), but tuned for frames:
 * a width close to a rollup step snaps to it, so a day at the default frame count reads the 5m
 * tier rather than a day of raw samples for every interface on the map.
 *
 * `at()` is the single instant flavour: one frame, the last few minutes before that moment.
 */
class GetMapPlayback
{
    /** Hard ceiling on frames per response, whatever the caller asks for. */
    public const MAX_FRAMES = 300;

    public const DEFAULT_FRAMES = 240;

    /**
     * Series values (keys x frames) one response carries when the caller doesn't pick a limit.
     * About 24 frames on a 2000 device map, which reads in well under a second.
     */
    public const CHUNK_VALUES = 120_000;

    public const MIN_CHUNK = 12;

    /** Narrowest frame on raw samples, in seconds. */
    public const MIN_STEP = 60;

    /** How far back an `at` frame looks for samples while the moment is still in raw history. */
    public const AT_LOOKBACK = 300;

    /** Precision for a percentage: a tenth under 10, whole numbers above, as the tiles show it. */
    private const AS_SHOWN = -1;

    public function __construct(
        private readonly HistoryQuery $history,
        private readonly HistoryTiers $tiers,
    ) {}

    /**
     * Frames over [from, to). The axis, `down` and `outages` always cover the whole window, but the
     * interface and ping series only cover frames [offset, offset + limit), so a big map loads a
     * long window in chunks (the series are the heavy part, the rest is small). Without a limit the
     * chunk is sized to the map, see CHUNK_VALUES.
     *
     * @return array<string, mixed>
     */
    public function range(Map $map, Carbon $from, Carbon $to, ?int $points = null, int $offset = 0, ?int $limit = null): array
    {
        $from = $this->local($from);
        $to = $this->local($to);
        $grid = $this->plan($from, $to, max(2, min(self::MAX_FRAMES, $points ?? self::DEFAULT_FRAMES)));
        $count = (int) ceil($grid['origin']->diffInSeconds($to) / $grid['bucketSeconds']);
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $frames[] = $grid['origin']->getTimestamp() + $i * $grid['bucketSeconds'];
        }

        return $this->build($map, $grid, $to, $frames, 'range', null, max(0, min($offset, $count)), $limit);
    }

    /** @return array<string, mixed> */
    public function at(Map $map, Carbon $at): array
    {
        $at = $this->local($at);
        if ($at->copy()->subSeconds(self::AT_LOOKBACK)->greaterThanOrEqualTo($this->tiers->cutoff('raw'))) {
            // The five minutes leading up to the moment, straight from raw samples.
            $grid = ['tier' => 'raw', 'bucketSeconds' => self::AT_LOOKBACK, 'origin' => $at->copy()->subSeconds(self::AT_LOOKBACK)];
            $to = $at->copy();
        } else {
            // Raw's gone, so the rollup bucket the moment falls in is as close as it gets.
            $tier = $at->greaterThanOrEqualTo($this->tiers->cutoff('5m')) ? '5m' : '1h';
            $step = HistoryTiers::step($tier);
            $grid = ['tier' => $tier, 'bucketSeconds' => $step, 'origin' => HistoryTiers::floorTo($at, $step)];
            $to = $grid['origin']->copy()->addSeconds($step);
        }

        $data = $this->build($map, $grid, $to, [$at->getTimestamp()], 'at', $at, 0, 1);
        $data['at'] = $at->toIso8601String();

        return $data;
    }

    /**
     * Frame width and source tier for a range. Mirrors HistoryTiers::plan with the frame count as
     * the point target, plus two tweaks: a width within half again of a rollup step rounds up to
     * that tier (24h / 300 frames is 288s, and 5 minute frames off the 5m rollups are far cheaper
     * than a day of raw for the same picture; a week at 240 is 42 minutes, hourly frames read 12x
     * fewer rows), and the frame cap is enforced after snapping.
     *
     * @return array{tier:string, bucketSeconds:int, origin:Carbon}
     */
    public function plan(Carbon $from, Carbon $to, int $points): array
    {
        $span = max(1, $from->diffInSeconds($to));
        // nothing polls much faster than once a minute, finer frames would mostly be empty
        $desired = max(self::MIN_STEP, (int) ceil($span / $points));

        $order = ['raw', ...array_keys(HistoryTiers::ROLLUPS)];
        $idx = 0;
        foreach ($order as $i => $tier) {
            if (HistoryTiers::step($tier) <= $desired * 1.5) {
                $idx = $i;
            }
        }
        while ($idx < count($order) - 1 && $from->lessThan($this->tiers->cutoff($order[$idx]))) {
            $idx++;
        }
        $tier = $order[$idx];

        if ($tier === 'raw') {
            $bucket = max($desired, (int) ceil($span / self::MAX_FRAMES));

            return ['tier' => 'raw', 'bucketSeconds' => $bucket, 'origin' => $from->copy()];
        }

        $step = HistoryTiers::step($tier);
        $origin = HistoryTiers::floorTo($from, $step);
        $bucket = $step * max(1, (int) round($desired / $step));
        // snapping the origin back can add a frame, rounding can add a few, never past the cap
        while (ceil($origin->diffInSeconds($to) / $bucket) > self::MAX_FRAMES) {
            $bucket += $step;
        }

        return ['tier' => $tier, 'bucketSeconds' => $bucket, 'origin' => $origin];
    }

    /**
     * @param  array{tier:string, bucketSeconds:int, origin:Carbon}  $grid
     * @param  list<int>  $frames
     * @return array<string, mixed>
     */
    private function build(Map $map, array $grid, Carbon $to, array $frames, string $mode, ?Carbon $at, int $offset, ?int $limit): array
    {
        // Through the relation so the Device visibility scope applies: a restricted operator only
        // gets devices they can see, even on a map they've been granted.
        $deviceIds = $map->devices()->pluck('devices.id')->map(fn ($id) => (int) $id)->all();
        $interfaceIds = $deviceIds === [] ? [] : Link::query()
            ->whereIn('a_device_id', $deviceIds)->whereIn('b_device_id', $deviceIds)
            ->get(['a_interface_id', 'b_interface_id'])
            ->flatMap(fn (Link $l) => [$l->a_interface_id, $l->b_interface_id])
            ->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();

        // No limit asked for: as many frames as fit the budget, so a small map gets the whole
        // window in one go and a big one gets a first chunk quickly and pages through the rest.
        // A frame wider than its source rows costs a few rows per key (raw is polled about once
        // a minute), so it counts for more. Devices count twice, once for ping and once for health.
        $rowsPerFrame = max(1, intdiv($grid['bucketSeconds'], $grid['tier'] === 'raw' ? self::MIN_STEP : HistoryTiers::step($grid['tier'])));
        $limit ??= max(self::MIN_CHUNK, intdiv(self::CHUNK_VALUES, max(1, (2 * count($deviceIds) + count($interfaceIds)) * $rowsPerFrame)));
        $limit = max(0, min(count($frames) - $offset, $limit));

        // The chunk's own grid: same bucket width, origin moved up to the chunk's first frame, so
        // every bucket lands exactly where it would in a read of the whole window.
        $chunk = ['tier' => $grid['tier'], 'bucketSeconds' => $grid['bucketSeconds'], 'origin' => $grid['origin']->copy()->addSeconds($offset * $grid['bucketSeconds'])];
        $chunkTo = $grid['origin']->copy()->addSeconds(($offset + $limit) * $grid['bucketSeconds'])->min($to);

        $interfaces = $this->series('interface', 'interface_id', $interfaceIds, $chunk, $chunkTo, $limit, [
            'bps_in' => 0,
            'bps_out' => 0,
        ]);
        $devices = $this->series('ping', 'device_id', $deviceIds, $chunk, $chunkTo, $limit, [
            'rtt_ms' => 2,
            'loss_pct' => 1,
        ]);
        // Rounded to what the tiles and inspector display anyway, which on a map where every device
        // reports all three is a good third off the health arrays against a flat tenth.
        $health = $this->series('device_metric', 'device_id', $deviceIds, $chunk, $chunkTo, $limit, [
            'cpu_pct' => self::AS_SHOWN,
            'mem_used_pct' => self::AS_SHOWN,
            'temp_c' => 0,
        ], true);
        [$down, $outages] = $this->outages($deviceIds, $grid, $to, count($frames), $at);

        return [
            'mode' => $mode,
            'tier' => $grid['tier'],
            'step' => $grid['bucketSeconds'],
            'from' => $grid['origin']->toIso8601String(),
            'to' => $to->toIso8601String(),
            'frames' => $frames,
            // the series below hold frames [offset, offset + limit) only
            'offset' => $offset,
            'limit' => $limit,
            'device_ids' => $deviceIds,
            'interfaces' => (object) $interfaces,
            'devices' => (object) $devices,
            'health' => (object) $health,
            'down' => (object) $down,
            'outages' => $outages,
        ];
    }

    /**
     * Dense per-key arrays for one family: the bucketed rows from HistoryQuery (or straight off the
     * rollup tier, see below) laid onto the frame axis, one value per frame and null where there
     * was no sample. Keys with no samples at all are left out, and with $sparse so is any metric
     * that's null in every frame (the key goes too if that leaves it empty).
     *
     * @param  list<int>  $ids
     * @param  array<string, int>  $metrics  metric => decimals to round it to (or AS_SHOWN)
     * @return array<int, array<string, list<int|float|null>>>
     */
    private function series(string $family, string $key, array $ids, array $grid, Carbon $to, int $n, array $metrics, bool $sparse = false): array
    {
        if ($ids === [] || $n === 0) {
            return [];
        }

        $filter = "{$key} = ANY(?::bigint[])";
        $idList = '{'.implode(',', $ids).'}';
        $origin = $grid['origin']->format('Y-m-d H:i:s');
        $step = $grid['bucketSeconds'];
        $segments = $this->history->segments($family, $grid, $to);
        // date_part rather than extract: extract gives numeric, and that alone was a third of the time
        $index = "(date_part('epoch', %s - ?::timestamp) / ?)::int AS i";

        $queries = [];
        if ($grid['tier'] !== 'raw' && $step === HistoryTiers::step($grid['tier']) && $segments[0][0] === $grid['tier']) {
            // Frames the same width as the rollup rows: up to that tier's watermark each row already
            // is a frame, so read it as is. HistoryQuery gets the same numbers but re-groups every
            // row twice, which on a 2000 device map was most of the time spent. Whatever the tier
            // hasn't closed yet (the last few minutes) still goes through HistoryQuery below.
            [, $start, $end] = $segments[0];
            $avgs = array_map(fn ($m) => "{$m}_sum / NULLIF({$m}_cnt, 0) AS {$m}", array_keys($metrics));
            $queries[] = ["SELECT {$key} AS k, ".sprintf($index, 'bucket').', '.implode(', ', $avgs).'
                FROM '.HistoryFamilies::rollupTable($family, $grid['tier'])."
                WHERE {$filter} AND bucket >= ?::timestamp AND bucket < ?::timestamp",
                [$origin, $step, $idList, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]];
            // the watermark sits on a step boundary, so the rest stays on the same frame edges
            $grid = ['origin' => $end->copy()] + $grid;
        }
        if ($grid['origin']->lessThan($to)) {
            [$inner, $params] = $this->history->sql($family, $grid, $to, array_map(fn () => ['avg'], $metrics), $filter, [$idList]);
            $queries[] = ["SELECT {$key} AS k, ".sprintf($index, 'bucket::timestamp').', '.implode(', ', array_keys($metrics))." FROM ({$inner}) s",
                [$origin, $step, ...$params]];
        }

        // Flat (key, frame, values) rows laid onto full-length arrays here, rounding as they go.
        // Cheaper than json_agg'ing per key in Postgres, which was most of the query on big maps.
        $empty = array_fill(0, $n, null);
        $out = [];
        foreach ($queries as [$sql, $params]) {
            foreach (DB::cursor($sql, $params) as $r) {
                $i = (int) $r->i;
                if ($i < 0 || $i >= $n) {
                    continue;
                }
                $k = (int) $r->k;
                $out[$k] ??= array_fill_keys(array_keys($metrics), $empty);
                foreach ($metrics as $m => $precision) {
                    if ($r->{$m} !== null) {
                        $v = (float) $r->{$m};
                        $p = $precision === self::AS_SHOWN ? (abs($v) < 10 ? 1 : 0) : $precision;
                        $out[$k][$m][$i] = $p === 0 ? (int) round($v) : round($v, $p);
                    }
                }
            }
        }

        if ($sparse) {
            foreach ($out as $k => $row) {
                $row = array_filter($row, fn (array $vals) => array_filter($vals, fn ($v) => $v !== null) !== []);
                if ($row === []) {
                    unset($out[$k]);
                } else {
                    $out[$k] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * Down frames per device plus the raw outage list, for outages that overlap the window. A
     * device counts as down in a frame if any part of an outage falls inside it, so a short drop
     * isn't lost on a wide frame. In `at` mode it's down only if the outage covers that moment.
     *
     * @param  list<int>  $deviceIds
     * @return array{0: array<int, list<int>>, 1: list<array{0:int, 1:int, 2:?int}>}
     */
    private function outages(array $deviceIds, array $grid, Carbon $to, int $n, ?Carbon $at): array
    {
        if ($deviceIds === []) {
            return [[], []];
        }

        $origin = $grid['origin'];
        $step = $grid['bucketSeconds'];
        $rows = DB::table('outages')
            ->whereRaw('device_id = ANY(?::bigint[])', ['{'.implode(',', $deviceIds).'}'])
            ->where('started_at', '<', $to->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $origin->format('Y-m-d H:i:s')))
            ->orderBy('started_at')
            ->get(['device_id', 'started_at', 'ended_at']);

        $down = [];
        $list = [];
        $o = $origin->getTimestamp();
        foreach ($rows as $r) {
            $start = Carbon::parse($r->started_at)->getTimestamp();
            $end = $r->ended_at !== null ? Carbon::parse($r->ended_at)->getTimestamp() : null;
            $list[] = [(int) $r->device_id, $start, $end];

            if ($at !== null) {
                $t = $at->getTimestamp();
                if ($start <= $t && ($end === null || $end > $t)) {
                    $down[(int) $r->device_id] = [0];
                }

                continue;
            }

            $first = max(0, intdiv($start - $o, $step));
            $last = $end === null ? $n - 1 : min($n - 1, (int) ceil(($end - $o) / $step) - 1);
            for ($i = $first; $i <= $last; $i++) {
                $down[(int) $r->device_id][$i] = $i;
            }
        }

        return [array_map(fn (array $idx) => array_values($idx), $down), $list];
    }

    /** Samples are stored as naive app-timezone timestamps, so work in that zone throughout. */
    private function local(Carbon $t): Carbon
    {
        return $t->copy()->setTimezone(config('app.timezone'));
    }
}
