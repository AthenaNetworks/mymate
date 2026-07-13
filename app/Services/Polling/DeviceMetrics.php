<?php

namespace App\Services\Polling;

/**
 * One device's resource reading. Every field is nullable - a device may expose some
 * metrics and not others (a switch with no temp sensor, an agent that answers cpu but
 * not memory), and a null just means "not available", not zero.
 */
class DeviceMetrics
{
    public function __construct(
        public readonly ?float $cpuPct = null,
        public readonly ?float $memUsedPct = null,
        public readonly ?float $tempC = null,
    ) {}

    /** True when nothing was read - the poller skips these so history has no fake zeroes. */
    public function isEmpty(): bool
    {
        return $this->cpuPct === null && $this->memUsedPct === null && $this->tempC === null;
    }

    /** Clamp a percentage into 0-100, or null. */
    public static function clampPct(?float $v): ?float
    {
        if ($v === null) {
            return null;
        }

        return max(0.0, min(100.0, $v));
    }
}
