<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Operator-editable connection to the **CMR controller** sidecar (`cmr-ctrl`). MikroTik devices
 * in controller-managed mode dial in to it on tcp/54321; My Mate reads what it has paired over
 * its loopback admin API and offers each device in the discovery review queue.
 *
 * Off until an admin turns it on (or deploy/cmr/provision.sh does), so an install that never
 * runs the daemon doesn't log a failed sync every minute. The admin token is encrypted at rest
 * and never returned, as in {@see BackupSettings}. The controller's own identity key never
 * leaves the daemon: My Mate only reads its public fingerprint.
 *
 * Like the Rusted URL, the API URL is admin-only trusted infrastructure on loopback, so it is
 * deliberately NOT run through {@see OutboundHostGuard}.
 */
class CmrSettings
{
    private const KEY = 'cmr.controller';

    /** @return array<string,mixed>|null */
    private function raw(): ?array
    {
        return Setting::where('key', self::KEY)->first()?->value;
    }

    public function enabled(): bool
    {
        return (bool) (($this->raw() ?? [])['enabled'] ?? false);
    }

    public function apiUrl(): string
    {
        $c = $this->raw() ?? [];

        return rtrim((string) ($c['api_url'] ?? config('mymate.cmr.url')), '/');
    }

    public function timeout(): int
    {
        return (int) config('mymate.cmr.timeout', 10);
    }

    public function apiToken(): string
    {
        $c = $this->raw() ?? [];
        if (! empty($c['api_token'])) {
            try {
                return Crypt::decryptString($c['api_token']);
            } catch (\Throwable) {
                return '';
            }
        }

        return (string) config('mymate.cmr.token', '');
    }

    /** The host name or IP devices are told to connect to (defaults to this install's APP_URL host). */
    public function publicHost(): string
    {
        $c = $this->raw() ?? [];
        $host = (string) ($c['public_host'] ?? '');

        return $host !== '' ? $host : (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public function configured(): bool
    {
        return $this->apiUrl() !== '' && $this->apiToken() !== '';
    }

    /** Turned on and pointed at a daemon - what the scheduled sync checks. */
    public function active(): bool
    {
        return $this->enabled() && $this->configured();
    }

    /** @return array<string,mixed> */
    public function publicView(): array
    {
        $c = $this->raw() ?? [];

        return [
            'enabled' => $this->enabled(),
            'api_url' => (string) ($c['api_url'] ?? config('mymate.cmr.url')),
            'public_host' => (string) ($c['public_host'] ?? ''),
            'effective_host' => $this->publicHost(),
            'port' => (int) config('mymate.cmr.port', 54321),
            'api_token_set' => ! empty($c['api_token']) || (string) config('mymate.cmr.token', '') !== '',
            'configured' => $this->configured(),
        ];
    }

    /**
     * Persist the controller config. A null/absent token keeps the stored one, '' clears it.
     *
     * @param  array<string,mixed>  $input
     */
    public function save(array $input): void
    {
        $existing = $this->raw() ?? [];

        $token = $existing['api_token'] ?? null;
        if (array_key_exists('api_token', $input) && $input['api_token'] !== null) {
            $token = $input['api_token'] === '' ? null : Crypt::encryptString((string) $input['api_token']);
        }

        Setting::updateOrCreate(['key' => self::KEY], [
            'type' => 'cmr',
            'value' => [
                'enabled' => (bool) ($input['enabled'] ?? $existing['enabled'] ?? false),
                'api_url' => rtrim((string) ($input['api_url'] ?? $existing['api_url'] ?? config('mymate.cmr.url')), '/'),
                'public_host' => (string) ($input['public_host'] ?? $existing['public_host'] ?? ''),
                'api_token' => $token,
            ],
        ]);
    }
}
