<?php

namespace App\Services\Backup;

use App\Support\BackupSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin server-side client for the **Rusted** backup engine's HTTP API.
 * Mirrors the shipped LibreNMS relay (`AthenaNetworks\RustedLibrenms\Support\RustedClient`)
 * but on My Mate's conventions: the bearer token stays server-side (never reaches the
 * browser), Rusted binds to loopback, and My Mate proxies the browser's needs.
 *
 * Rusted's API surface (README): bearer-auth on every `/api/*` route, `/healthz` open.
 *   GET  /api/drivers - GET/POST /api/credentials - DELETE /api/credentials/{name}
 *   GET  /api/devices - POST /api/devices (upsert) - GET/DELETE /api/devices/{name}
 *   GET  /api/devices/{name}/history - GET /api/devices/{name}/config (text)
 *   POST /api/devices/{name}/backup (run now)
 *
 * The Rusted URL is trusted admin-only infra pointing at loopback, so - unlike operator-
 * supplied webhooks - calls here are NOT run through OutboundHostGuard (see BackupSettings).
 */
class RustedClient
{
    public function __construct(private BackupSettings $settings) {}

    /** Configured base client: base URL + bearer token + JSON + timeout, no redirects. */
    private function client(): PendingRequest
    {
        if (! $this->settings->configured()) {
            throw new RuntimeException('The backup engine (Rusted) is not configured - set its URL and token in Settings.');
        }

        return Http::baseUrl($this->settings->apiUrl())
            ->withToken($this->settings->apiToken())
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->settings->timeout());
    }

    /** Reachability probe - true if Rusted answers `/healthz`. Never throws. */
    public function healthy(): bool
    {
        try {
            return Http::baseUrl($this->settings->apiUrl())
                ->timeout(min(10, $this->settings->timeout()))
                ->get('/healthz')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function drivers(): array
    {
        return $this->client()->get('/api/drivers')->throw()->json() ?? [];
    }

    /**
     * Install a generated SSH key on a RouterOS device over its API and get the private key
     * back, so the device can be backed up over SSH (RouterOS won't export over the API).
     *
     * @return array{user:string, private_key:string, ssh_port:int, ssh_enabled:bool, ssh_enabled_by:bool}
     */
    public function provisionMikrotikSshKey(string $host, int $port, string $username, string $password): array
    {
        return $this->client()
            ->post('/api/provision/mikrotik-ssh-key', [
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
            ])
            ->throw()
            ->json();
    }

    /** Upsert a Rusted credential (name-keyed). @param array<string,mixed> $payload */
    public function putCredential(array $payload): void
    {
        $this->client()->post('/api/credentials', $payload)->throw();
    }

    /** Upsert a Rusted device (name-keyed). @param array<string,mixed> $payload */
    public function putDevice(array $payload): void
    {
        $this->client()->post('/api/devices', $payload)->throw();
    }

    /** Remove a Rusted device. A missing device (404) is treated as already gone. */
    public function deleteDevice(string $name): void
    {
        $res = $this->client()->delete('/api/devices/'.rawurlencode($name));
        if ($res->status() !== 404) {
            $res->throw();
        }
    }

    /**
     * Backup history for one device, newest first as Rusted returns it. Each row:
     * {started_at, finished_at, status, message, bytes, commit}. A device Rusted doesn't
     * know yet (404) has no history -> [].
     *
     * @return array<int, array<string,mixed>>
     */
    public function history(string $name): array
    {
        $res = $this->client()->get('/api/devices/'.rawurlencode($name).'/history');
        if ($res->status() === 404) {
            return [];
        }

        return $res->throw()->json() ?? [];
    }

    /** Latest stored config text for a device, or null if none is stored yet (404). */
    public function latestConfig(string $name): ?string
    {
        $res = $this->client()->get('/api/devices/'.rawurlencode($name).'/config');
        if ($res->status() === 404) {
            return null;
        }

        return $res->throw()->body();
    }

    /**
     * Trigger a backup now and return Rusted's result ({status, message, commit, bytes, ...}).
     * Synchronous - Rusted SSHes to the device and captures the config before responding,
     * which is why callers run this on the isolated `backup` queue with a long timeout.
     *
     * @return array<string,mixed>
     */
    public function backup(string $name): array
    {
        return $this->client()->post('/api/devices/'.rawurlencode($name).'/backup')->throw()->json() ?? [];
    }
}
