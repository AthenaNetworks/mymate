<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Operator-editable outgoing mail server. The SMTP host/port/auth/
 * from-identity live in one `settings` row (key {@see self::KEY}) so the mail server can
 * be set in-app instead of editing `.env`. The password is **encrypted at rest** (Laravel
 * `Crypt`) and **never returned** by the API ({@see publicView()} exposes only `password_set`).
 *
 * At send time {@see apply()} layers these onto the runtime mail config so `Mail` uses the
 * operator's server; with nothing configured it returns null and Laravel's default mailer
 * (env/`config/mail.php`) is used unchanged - so this is purely additive.
 */
class MailSettings
{
    private const KEY = 'mail.smtp';

    /** Encryption options offered in the UI -> SMTP scheme. */
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function configured(): bool
    {
        $raw = $this->raw();

        return ! empty($raw['host']);
    }

    /** Raw stored bag (password still ciphertext), or null. @return array<string,mixed>|null */
    private function raw(): ?array
    {
        return Setting::where('key', self::KEY)->first()?->value;
    }

    /**
     * API-safe view - every field except the password, plus a `password_set` flag so the
     * UI can show "leave blank to keep" without ever shipping the secret to the browser.
     *
     * @return array<string,mixed>
     */
    public function publicView(): array
    {
        $c = $this->raw() ?? [];

        return [
            'host' => $c['host'] ?? '',
            'port' => (int) ($c['port'] ?? 587),
            'encryption' => $c['encryption'] ?? 'tls',
            'username' => $c['username'] ?? '',
            'from_address' => $c['from_address'] ?? '',
            'from_name' => $c['from_name'] ?? 'My Mate',
            'password_set' => ! empty($c['password']),
            'configured' => $this->configured(),
        ];
    }

    /**
     * Persist operator SMTP config. The password is encrypted; a null/absent password keeps
     * the existing one (so editing other fields doesn't wipe it), an empty string clears it.
     *
     * @param  array<string,mixed>  $input
     */
    public function save(array $input): void
    {
        $existing = $this->raw() ?? [];
        $password = $existing['password'] ?? null; // ciphertext (or null)

        if (array_key_exists('password', $input) && $input['password'] !== null) {
            $password = $input['password'] === ''
                ? null
                : Crypt::encryptString((string) $input['password']);
        }

        Setting::updateOrCreate(['key' => self::KEY], [
            'type' => 'mail',
            'value' => [
                'host' => (string) $input['host'],
                'port' => (int) $input['port'],
                'encryption' => (string) $input['encryption'],
                'username' => (string) ($input['username'] ?? ''),
                'password' => $password,
                'from_address' => (string) $input['from_address'],
                'from_name' => (string) ($input['from_name'] ?? 'My Mate'),
            ],
        ]);
    }

    /**
     * Layer the operator SMTP config onto the runtime mail config and return the mailer name
     * to send through ('smtp'), or null when nothing is configured (use the default mailer).
     * Decrypts the password here - the only place it's read back.
     */
    public function apply(): ?string
    {
        $c = $this->raw();
        if ($c === null || empty($c['host'])) {
            return null;
        }

        //  SSRF hardening: re-check the target immediately before every send (the
        // Form Request already checked at save-time - this closes the DNS-rebinding gap
        // between the two). Falls back to the default mailer, same as "unconfigured".
        if (! OutboundHostGuard::isSafeHost((string) $c['host'])) {
            EngineLog::error('mail: configured host is not a safe outbound destination');

            return null;
        }

        $password = null;
        if (! empty($c['password'])) {
            try {
                $password = Crypt::decryptString($c['password']);
            } catch (\Throwable) {
                $password = null; // unreadable ciphertext -> treat as no auth, never crash a send
            }
        }

        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            // Laravel 13: 'smtps' = implicit TLS (465); 'smtp' = STARTTLS/none (587/25).
            'scheme' => ($c['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => $c['host'],
            'port' => (int) ($c['port'] ?? 587),
            'username' => ($c['username'] ?? '') ?: null,
            'password' => $password ?: null,
            'timeout' => null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
        config()->set('mail.from', [
            'address' => $c['from_address'] ?? config('mail.from.address'),
            'name' => $c['from_name'] ?? 'My Mate',
        ]);
        config()->set('mail.default', 'smtp');
        Mail::purge('smtp'); // drop any mailer resolved against the old config

        return 'smtp';
    }

    /** Send a one-off test message through the effective mailer (throws on SMTP failure). */
    public function sendTest(string $to): void
    {
        $mailer = $this->apply();
        Mail::mailer($mailer)->raw(
            'This is a test email from My Mate. If you received it, your outgoing mail server is configured correctly.',
            fn ($m) => $m->to($to)->subject('My Mate - test email'),
        );
    }
}
