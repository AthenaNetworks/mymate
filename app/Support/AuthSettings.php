<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Operator-editable authentication policy. Right now that's just one flag - whether passkeys
 * (WebAuthn) are mandatory - stored in a single `settings` row (key {@see self::KEY}) so it can be
 * toggled in-app, falling back to the config default (`mymate.auth.passkey_required`) when unset.
 *
 * When it's on, every operator who has no passkey and isn't marked `passkey_exempt` is forced to
 * enrol one before they can do anything (enforced by the EnsurePasskeyVerified middleware).
 * API keys (bearer tokens) are never affected.
 */
class AuthSettings
{
    private const KEY = 'auth.security';

    public function passkeyRequired(): bool
    {
        $raw = Setting::where('key', self::KEY)->first()?->value;

        return (bool) ($raw['passkey_required'] ?? config('mymate.auth.passkey_required', false));
    }

    public function setPasskeyRequired(bool $required): void
    {
        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => ['passkey_required' => $required], 'type' => 'json'],
        );
    }

    /** @return array<string,bool> */
    public function publicView(): array
    {
        return ['passkey_required' => $this->passkeyRequired()];
    }
}
