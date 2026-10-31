<?php

namespace App\Services\Cmr;

use App\Support\CmrSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the `cmr-ctrl` admin API (github.com/AthenaNetworks/go-mikrotik-cmr,
 * pkg/controller/admin.go). The daemon binds it to loopback behind a bearer token; the token
 * stays server-side.
 *
 *   GET /healthz - GET /identity - GET /devices - GET /devices/{id}
 *   PUT/DELETE /devices/{id}/config - DELETE /devices/{id} - GET /sessions
 *
 * The admin API reports `connected` per device and the source IP of its last connection
 * (`last_remote_addr`), which is all the sync needs.
 */
class CmrClient
{
    public function __construct(private CmrSettings $settings) {}

    private function client(): PendingRequest
    {
        if (! $this->settings->configured()) {
            throw new RuntimeException('The CMR controller is not configured - set its URL and token in Settings.');
        }

        return Http::baseUrl($this->settings->apiUrl())
            ->withToken($this->settings->apiToken())
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->settings->timeout());
    }

    /** True if the daemon answers `/healthz` with our token. Never throws. */
    public function healthy(): bool
    {
        try {
            return $this->client()->get('/healthz')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The controller's pinned certificate fingerprint (what each device trusts), its listen
     * address and the daemon version, or null when it can't be reached. Never throws.
     *
     * @return array{fingerprint:string, listen_addr:string, version:string}|null
     */
    public function identity(): ?array
    {
        try {
            $res = $this->client()->get('/identity');
            if (! $res->successful() || ! is_array($res->json())) {
                return null;
            }
            $j = $res->json();

            return [
                'fingerprint' => (string) ($j['fingerprint'] ?? ''),
                'listen_addr' => (string) ($j['listen_addr'] ?? ''),
                'version' => (string) ($j['version'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every device the controller knows, connected or not. Each row: {id, mac, identity, board,
     * arch, os, connected, last_remote_addr, first_seen, last_seen, config}.
     *
     * @return array<int, array<string,mixed>>
     */
    public function devices(): array
    {
        $rows = $this->client()->get('/devices')->throw()->json();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
