<?php

namespace App\Services\Polling;

/**
 * The result of computing one device's throughput tick - the rows to persist and
 * the frames to broadcast. Kept separate from persistence/broadcast so the
 * orchestrator can bulk-upsert a whole batch in one round trip and coalesce the
 * broadcast.
 */
final readonly class DevicePollResult
{
    /**
     * @param  list<array<string, mixed>>  $upsertRows  interface rows for a bulk upsert
     * @param  list<array<string, mixed>>  $frames  per-interface broadcast frames
     */
    public function __construct(
        public int $deviceId,
        public string $status,
        public array $upsertRows,
        public array $frames,
    ) {}

    /** The per-device broadcast payload (one entry in a coalesced util event). */
    public function frame(): array
    {
        return [
            'device_id' => $this->deviceId,
            'status' => $this->status,
            'interfaces' => $this->frames,
        ];
    }
}
