<?php

namespace App\Support;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpCredential;

/**
 * Read a single custom-sensor value off a device: SNMP GET a scalar OID, or walk a table and
 * reduce it by `agg`, then scale by `divisor`. Shared by the polling loop
 * ({@see \App\Actions\Polling\PollSensors}) and the "test this OID" endpoint so a test behaves
 * exactly like a real poll. SNMP transport errors propagate; a non-numeric / empty result is null.
 */
class SensorReader
{
    public function __construct(private SnmpClient $snmp) {}

    public function read(string $host, SnmpCredential $cred, string $oid, string $mode, ?string $agg, float $divisor): ?float
    {
        $raw = $mode === 'walk'
            ? self::aggregate($this->snmp->walk($host, $cred, $oid), (string) ($agg ?: 'sum'))
            : self::firstNumeric($this->snmp->get($host, $cred, [$oid]));

        if ($raw === null) {
            return null;
        }

        return $divisor != 0.0 ? $raw / $divisor : $raw;
    }

    /**
     * First numeric value in an SNMP GET result (leading signed/decimal number), or null.
     *
     * @param  array<int|string, string>  $res
     */
    public static function firstNumeric(array $res): ?float
    {
        foreach ($res as $value) {
            if (preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1) {
                return (float) $m[0];
            }
        }

        return null;
    }

    /**
     * Reduce a table walk to one value. `count` counts every returned row (numeric or not); the
     * rest operate on the numeric values only. An empty walk -> null (no reading).
     *
     * @param  array<int|string, string>  $res
     */
    public static function aggregate(array $res, string $agg): ?float
    {
        if ($agg === 'count') {
            return $res === [] ? null : (float) count($res);
        }

        $nums = [];
        foreach ($res as $value) {
            if (preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1) {
                $nums[] = (float) $m[0];
            }
        }
        if ($nums === []) {
            return null;
        }

        return match ($agg) {
            'avg' => array_sum($nums) / count($nums),
            'max' => max($nums),
            'min' => min($nums),
            default => array_sum($nums), // sum
        };
    }
}
