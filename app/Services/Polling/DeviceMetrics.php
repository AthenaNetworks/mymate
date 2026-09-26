<?php

namespace App\Services\Polling;

/**
 * One device's resource + RF reading. Every field is nullable - a device may expose some
 * metrics and not others (a switch with no temp sensor; a wired router with no wireless), and
 * a null just means "not available", not zero. The RF fields (signal/snr/ccq/clients) are only
 * populated for wireless gear.
 *
 * The device page extras: `cpuLoads` is the load per processor (index => %, the same values
 * cpuPct averages), `storages` the disk / memory entries (null = not read, [] = read and the box
 * has none worth keeping), `uptimeSeconds` the host uptime.
 */
class DeviceMetrics
{
    /**
     * @param  array<int, float>|null  $cpuLoads
     * @param  list<StorageReading>|null  $storages
     */
    public function __construct(
        public readonly ?float $cpuPct = null,
        public readonly ?float $memUsedPct = null,
        public readonly ?float $tempC = null,
        public readonly ?float $signalDbm = null,
        public readonly ?float $snrDb = null,
        public readonly ?float $ccqPct = null,
        public readonly ?int $wirelessClients = null,
        public readonly ?int $ospfNeighbors = null,
        public readonly ?int $uptimeSeconds = null,
        public readonly ?array $cpuLoads = null,
        public readonly ?array $storages = null,
    ) {}

    /** True when nothing was read - the poller skips these so history has no fake zeroes. */
    public function isEmpty(): bool
    {
        return $this->cpuPct === null
            && $this->memUsedPct === null
            && $this->tempC === null
            && $this->signalDbm === null
            && $this->snrDb === null
            && $this->ccqPct === null
            && $this->wirelessClients === null
            && $this->ospfNeighbors === null
            && $this->uptimeSeconds === null
            && ($this->cpuLoads === null || $this->cpuLoads === [])
            && ($this->storages === null || $this->storages === []);
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
