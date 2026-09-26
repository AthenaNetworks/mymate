<?php

namespace App\Actions\History;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 95th percentile billing for a device's ports over a period (GitHub #28).
 *
 * Per port: p95 of the 5 minute in rates, of the out rates, and of max(in, out) per interval,
 * plus bytes moved each way. With more than one port selected there's also the aggregate: the
 * ports' rates summed per interval, then the percentile of that. See HistoryPercentile for the
 * method and the hourly fallback.
 *
 * Periods are calendar months in the app timezone. "this_month" runs to now, so it's a bill to
 * date, not a forecast.
 */
class GetPortBilling
{
    public function __construct(
        private readonly HistoryCatalog $catalog,
        private readonly HistoryPercentile $percentile,
    ) {}

    /** @return array{0:Carbon, 1:Carbon, 2:string} [from, to, label] */
    public static function period(string $name, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $now = now();

        return match ($name) {
            'last_month' => [$now->copy()->startOfMonth()->subMonthNoOverflow(), $now->copy()->startOfMonth(), $now->copy()->startOfMonth()->subMonthNoOverflow()->format('F Y')],
            'custom' => [$from ?? $now->copy()->subDays(30), $to ?? $now, 'Custom'],
            default => [$now->copy()->startOfMonth(), $now, $now->format('F Y').' to date'],
        };
    }

    /**
     * @param  list<string>  $keys  interface ids, empty = every port on the device
     * @return array<string, mixed>|null null when a key isn't one of the device's ports
     */
    public function __invoke(Device $device, array $keys, Carbon $from, Carbon $to, bool $aggregate): ?array
    {
        $scope = $this->catalog->scope('interface', $device);
        $narrowed = $scope === null ? null : $this->catalog->narrow($scope, $device, $keys);
        if ($narrowed === null) {
            return null;
        }
        [$filter, $bindings] = $narrowed;

        $result = $this->percentile->compute(
            'interface', $filter, $bindings, ['interface_id'], ['bps_in', 'bps_out'], $from, $to,
            greatest: ['bps_max' => ['bps_in', 'bps_out']], aggregate: $aggregate,
        );
        $step = $result['step'];

        $names = DB::table('interfaces')->where('device_id', $device->id)
            ->when($keys !== [], fn ($q) => $q->whereIn('id', array_map('intval', $keys)))
            ->orderBy('if_index')->orderBy('name')
            ->get(['id', 'name', 'description', 'speed_mbps']);

        $ports = [];
        foreach ($names as $iface) {
            $row = $result['keys'][(string) $iface->id] ?? null;
            if ($row === null && $keys === []) {
                continue; // no traffic at all in the period, keep the all-ports view tidy
            }
            $ports[] = [
                'key' => (string) $iface->id,
                'label' => $iface->name,
                'description' => $iface->description,
                'speed_mbps' => $iface->speed_mbps,
                ...self::figures($row, $step),
            ];
        }

        return [
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'resolution' => $result['resolution'],
            'precise' => $result['precise'],
            'step' => $step,
            'expected_samples' => (int) floor(max(0, $from->diffInSeconds($to)) / $step),
            'ports' => $ports,
            'aggregate' => $aggregate && $result['aggregate'] !== null ? self::figures($result['aggregate'], $step) : null,
        ];
    }

    /** @return array{p95_in:?float, p95_out:?float, p95_max:?float, bytes_in:?float, bytes_out:?float, samples:int} */
    private static function figures(?array $row, int $step): array
    {
        $bytes = fn (?float $sum) => $sum === null ? null : round($sum * $step / 8);

        return [
            'p95_in' => self::round($row['bps_in']['p95'] ?? null),
            'p95_out' => self::round($row['bps_out']['p95'] ?? null),
            'p95_max' => self::round($row['bps_max']['p95'] ?? null),
            'bytes_in' => $bytes($row['bps_in']['sum'] ?? null),
            'bytes_out' => $bytes($row['bps_out']['sum'] ?? null),
            'samples' => max($row['bps_in']['samples'] ?? 0, $row['bps_out']['samples'] ?? 0),
        ];
    }

    private static function round(?float $v): ?float
    {
        return $v === null ? null : round($v);
    }
}
