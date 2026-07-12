<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Operator-editable connection to the external **Rusted** config-backup engine ( /
 * FR-38). Rusted is a localhost Go sidecar that captures device configs over SSH; My Mate
 * talks to its HTTP API. The API bearer token + a default SSH credential (fallback for
 * devices whose own stored credential can't be reused) live in one `settings` row (key
 * {@see self::KEY}) so this is set in-app, not just `.env`.
 *
 * Secrets (`api_token`, `ssh_password`) are **encrypted at rest** (Laravel `Crypt`) and
 * **never returned** by the API - {@see publicView()} exposes only `*_set` flags. Mirrors
 * {@see MailSettings}. `.env`/`config('mymate.backup.*')` supply the defaults; a stored row
 * overrides them field-by-field.
 *
 * Note on SSRF: the Rusted URL is admin-only trusted infrastructure and points at loopback
 * by design, so - unlike operator-supplied alert webhooks / SMTP hosts - it is deliberately
 * NOT run through {@see OutboundHostGuard} (which would reject the loopback default).
 */
class BackupSettings
{
    private const KEY = 'backup.rusted';

    /** Raw stored bag (secrets still ciphertext), or null. @return array<string,mixed>|null */
    private function raw(): ?array
    {
        return Setting::where('key', self::KEY)->first()?->value;
    }

    /** The effective API base URL (stored override, else the config/env default). */
    public function apiUrl(): string
    {
        $c = $this->raw() ?? [];

        return rtrim((string) ($c['api_url'] ?? config('mymate.backup.url')), '/');
    }

    /** Per-call HTTP timeout in seconds. */
    public function timeout(): int
    {
        $c = $this->raw() ?? [];

        return (int) ($c['timeout'] ?? config('mymate.backup.timeout', 120));
    }

    /** The decrypted bearer token (stored, else the env default). Read-back point for the secret. */
    public function apiToken(): string
    {
        $c = $this->raw() ?? [];
        if (! empty($c['api_token'])) {
            try {
                return Crypt::decryptString($c['api_token']);
            } catch (\Throwable) {
                return ''; // unreadable ciphertext -> no token rather than crash
            }
        }

        return (string) config('mymate.backup.token', '');
    }

    /** Configured enough to attempt a call (a URL and a token are present). */
    public function configured(): bool
    {
        return $this->apiUrl() !== '' && $this->apiToken() !== '';
    }

    /**
     * The fallback SSH credential for devices whose own My Mate credential can't be reused
     * for SSH (e.g. an SNMP-only device). Returns null when none is set - callers then rely
     * solely on the per-device credential. Password is decrypted here.
     *
     * @return array{username: string, password: string, enable: string}|null
     */
    public function defaultSshCredential(): ?array
    {
        $c = $this->raw() ?? [];
        $username = (string) ($c['ssh_username'] ?? '');
        if ($username === '') {
            return null;
        }

        $password = '';
        if (! empty($c['ssh_password'])) {
            try {
                $password = Crypt::decryptString($c['ssh_password']);
            } catch (\Throwable) {
                $password = '';
            }
        }

        return ['username' => $username, 'password' => $password, 'enable' => (string) ($c['ssh_enable'] ?? '')];
    }

    /**
     * API-safe view - every field except the two secrets, plus `*_set` flags so the UI can
     * show "leave blank to keep" without ever shipping a secret to the browser.
     *
     * @return array<string,mixed>
     */
    public function publicView(): array
    {
        $c = $this->raw() ?? [];

        return [
            'api_url' => (string) ($c['api_url'] ?? config('mymate.backup.url')),
            'timeout' => (int) ($c['timeout'] ?? config('mymate.backup.timeout', 120)),
            'ssh_username' => (string) ($c['ssh_username'] ?? ''),
            'api_token_set' => ! empty($c['api_token']) || (string) config('mymate.backup.token', '') !== '',
            'ssh_password_set' => ! empty($c['ssh_password']),
            'configured' => $this->configured(),
        ];
    }

    /**
     * Persist operator Rusted config. The token + SSH password are encrypted; a null/absent
     * secret keeps the existing one (so editing other fields doesn't wipe it), an empty
     * string clears it. Mirrors {@see MailSettings::save()}.
     *
     * @param  array<string,mixed>  $input
     */
    public function save(array $input): void
    {
        $existing = $this->raw() ?? [];

        Setting::updateOrCreate(['key' => self::KEY], [
            'type' => 'backup',
            'value' => [
                'api_url' => rtrim((string) $input['api_url'], '/'),
                'timeout' => (int) ($input['timeout'] ?? 120),
                'ssh_username' => (string) ($input['ssh_username'] ?? ''),
                'ssh_enable' => (string) ($input['ssh_enable'] ?? ''),
                'api_token' => $this->nextSecret($input, 'api_token', $existing),
                'ssh_password' => $this->nextSecret($input, 'ssh_password', $existing),
            ],
        ]);
    }

    /**
     * Resolve one write-only secret on save: absent/null keeps the stored ciphertext,
     * '' clears it, a value is (re)encrypted.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $existing
     */
    private function nextSecret(array $input, string $key, array $existing): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return $existing[$key] ?? null; // keep
        }

        return $input[$key] === '' ? null : Crypt::encryptString((string) $input[$key]);
    }
}
