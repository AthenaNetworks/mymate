<?php

namespace App\Services\Polling;

/**
 * One storage entry on a device: a disk, the RAM, swap. Comes from the HOST-RESOURCES
 * hrStorageTable over SNMP (keyed by hrStorageIndex) or from /system/resource over the RouterOS
 * API (keyed by a fixed name). Sizes are bytes, already multiplied out of allocation units.
 */
class StorageReading
{
    /** hrStorageType (1.3.6.1.2.1.25.2.1.N) => our type name. */
    public const HR_TYPES = [
        1 => 'other',
        2 => 'ram',
        3 => 'virtual_memory',
        4 => 'fixed_disk',
        5 => 'removable_disk',
        6 => 'floppy_disk',
        7 => 'compact_disc',
        8 => 'ram_disk',
        9 => 'flash',
        10 => 'network_disk',
    ];

    /**
     * Types we keep. `other` is net-snmp's buffers / cached / shared memory lines, they're views
     * of RAM rather than storage and just clutter the list, and nobody graphs a CD drive.
     */
    public const KEPT_TYPES = ['ram', 'virtual_memory', 'fixed_disk', 'removable_disk', 'ram_disk', 'flash', 'network_disk'];

    public function __construct(
        public readonly string $key,
        public readonly string $descr,
        public readonly string $type,
        public readonly ?int $sizeBytes,
        public readonly ?int $usedBytes,
    ) {}

    public function usedPct(): ?float
    {
        if ($this->sizeBytes === null || $this->usedBytes === null || $this->sizeBytes <= 0) {
            return null;
        }

        return round(max(0.0, min(100.0, $this->usedBytes / $this->sizeBytes * 100)), 2);
    }

    /** Map an hrStorageType value (an OID like ".1.3.6.1.2.1.25.2.1.4", or just "4") to a type name. */
    public static function hrType(mixed $value): string
    {
        $v = trim((string) $value);
        if (in_array($v, self::HR_TYPES, true)) {
            return $v; // already one of ours, eg from the agent's RouterOS read
        }
        if (preg_match('/(?:25\.2\.1\.)?(\d+)$/', $v, $m) === 1) {
            return self::HR_TYPES[(int) $m[1]] ?? 'other';
        }

        return 'other';
    }

    /**
     * Build the storage list from hrStorageTable columns keyed by hrStorageIndex. Rows of a type
     * we don't keep, or with no size, are dropped. A missing type falls back to guessing from the
     * description (so an agent that only gave us descr/size/used still gets memory right).
     *
     * @param  array<string, string>  $descr
     * @param  array<string, string>  $types
     * @param  array<string, string>  $units
     * @param  array<string, string>  $sizes
     * @param  array<string, string>  $used
     * @return list<self>
     */
    public static function fromHrColumns(array $descr, array $types, array $units, array $sizes, array $used): array
    {
        $out = [];
        foreach ($descr as $index => $label) {
            $label = trim((string) $label, " \"\t\n");
            $type = isset($types[$index]) ? self::hrType($types[$index]) : self::guessType($label);
            if (! in_array($type, self::KEPT_TYPES, true)) {
                continue;
            }
            $size = self::units($sizes[$index] ?? null);
            if ($size === null || $size <= 0) {
                continue;
            }
            // hrStorageAllocationUnits is the bytes per unit. Missing means we can't turn the
            // counts into bytes, the percentage still works so keep it with unit 1.
            $unit = isset($units[$index]) && is_numeric($units[$index]) && (int) $units[$index] > 0 ? (int) $units[$index] : 1;
            $usedUnits = self::units($used[$index] ?? null);

            $out[] = new self(
                key: (string) $index,
                descr: $label !== '' ? mb_substr($label, 0, 255) : "storage {$index}",
                type: $type,
                sizeBytes: $size * $unit,
                usedBytes: $usedUnits === null ? null : $usedUnits * $unit,
            );
        }

        return $out;
    }

    /**
     * Split a walk of the whole hrStorageEntry (keys "column.index") into the columns
     * fromHrColumns wants.
     *
     * @param  array<string, string>  $entry
     * @return list<self>
     */
    public static function fromHrEntry(array $entry): array
    {
        $cols = self::entryColumns($entry);

        return self::fromHrColumns($cols[3], $cols[2], $cols[4], $cols[5], $cols[6]);
    }

    /**
     * The columns we use out of an hrStorageEntry walk ("column.index" => value), each keyed by
     * hrStorageIndex: 2 type, 3 descr, 4 allocation units, 5 size, 6 used.
     *
     * @param  array<string, string>  $entry
     * @return array<int, array<string, string>>
     */
    public static function entryColumns(array $entry): array
    {
        $cols = [2 => [], 3 => [], 4 => [], 5 => [], 6 => []];
        foreach ($entry as $key => $value) {
            $parts = explode('.', ltrim((string) $key, '.'), 2);
            if (count($parts) !== 2 || ! isset($cols[(int) $parts[0]])) {
                continue;
            }
            $cols[(int) $parts[0]][$parts[1]] = (string) $value;
        }

        return $cols;
    }

    /**
     * hrStorageSize / hrStorageUsed are Integer32, and a big disk on a small allocation unit
     * overflows into a negative on plenty of agents. Read those as the unsigned value they meant.
     */
    private static function units(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        return $n < 0 ? $n + 4294967296 : $n;
    }

    private static function guessType(string $label): string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'swap') || str_contains($l, 'virtual') => 'virtual_memory',
            str_contains($l, 'cache') || str_contains($l, 'buffer') || str_contains($l, 'shared') => 'other',
            str_contains($l, 'memory') || $l === 'ram' => 'ram',
            str_starts_with($l, '/') || str_contains($l, 'disk') || preg_match('/^[a-z]:\\\\/', $l) === 1 => 'fixed_disk',
            default => 'other',
        };
    }
}
