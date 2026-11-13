<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Crypt;

/**
 * Single sign-on (OpenID Connect) configuration, set by an admin in Settings > Security and stored
 * in one `settings` row (key {@see self::KEY}). The client secret is **encrypted at rest** and
 * **never returned** by the API - {@see publicView()} only says whether one is set.
 *
 * Two providers share one implementation (App\Services\Sso\OidcClient):
 *  - `entra`: Microsoft Entra ID. The admin gives the tenant (id or domain) and the issuer is
 *    derived from it. Only a single tenant is allowed - never the shared `common` /
 *    `organizations` / `consumers` endpoints, which would let any Microsoft account in.
 *  - `oidc`: any standard OpenID Connect provider, by issuer URL.
 *
 * Roles come from the provider: the `role_claim` in the ID token (Entra app roles arrive in
 * `roles`) must hold `admin_role` or `operator_role`, else the sign-in is refused.
 *
 * Accounts that SSO creates itself (nobody had made one with that email) can start locked down:
 * `restrict_new_users` makes a new operator restricted (no maps until an admin grants some), and
 * `default_group_ids` puts them in those groups, whose maps they then see. A mistyped email on an
 * account an admin set up in advance therefore fails closed instead of opening every map.
 */
class SsoSettings
{
    private const KEY = 'auth.sso';

    public const PROVIDERS = ['entra', 'oidc'];

    /** Entra's multi-tenant endpoints - refused, a sign-in must come from the company's own tenant. */
    public const SHARED_TENANTS = ['common', 'organizations', 'consumers'];

    private const DEFAULTS = [
        'enabled' => false,
        'provider' => 'entra',
        'tenant' => '',
        'issuer' => '',
        'client_id' => '',
        'client_secret' => null, // ciphertext
        'role_claim' => 'roles',
        'admin_role' => 'MyMate.Admin',
        'operator_role' => 'MyMate.Operator',
        'restrict_new_users' => true,
        'default_group_ids' => [],
    ];

    /** @return array<string,mixed> */
    private function raw(): array
    {
        $saved = Setting::where('key', self::KEY)->first()?->value;

        return array_replace(self::DEFAULTS, is_array($saved) ? $saved : []);
    }

    public function provider(): string
    {
        return in_array($this->raw()['provider'], self::PROVIDERS, true) ? $this->raw()['provider'] : 'entra';
    }

    /** The issuer to discover and to expect in the ID token's `iss` (no trailing slash). */
    public function issuer(): string
    {
        $c = $this->raw();
        if ($this->provider() === 'entra') {
            $tenant = trim((string) $c['tenant']);

            return $tenant === '' ? '' : 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/v2.0';
        }

        return rtrim(trim((string) $c['issuer']), '/');
    }

    public function clientId(): string
    {
        return trim((string) $this->raw()['client_id']);
    }

    public function clientSecret(): ?string
    {
        $c = $this->raw()['client_secret'];
        if (! is_string($c) || $c === '') {
            return null;
        }
        try {
            return Crypt::decryptString($c);
        } catch (\Throwable) {
            return null; // unreadable (APP_KEY changed) - treat as unset rather than crash a sign-in
        }
    }

    public function roleClaim(): string
    {
        return (string) $this->raw()['role_claim'] ?: 'roles';
    }

    public function adminRole(): string
    {
        return (string) $this->raw()['admin_role'];
    }

    public function operatorRole(): string
    {
        return (string) $this->raw()['operator_role'];
    }

    /** A new operator created by SSO starts restricted (sees no maps until granted some). */
    public function restrictNewUsers(): bool
    {
        return (bool) $this->raw()['restrict_new_users'];
    }

    /**
     * Groups a new operator created by SSO joins. Only groups that still exist - a deleted group
     * simply drops out of the list.
     *
     * @return list<int>
     */
    public function defaultGroupIds(): array
    {
        $ids = $this->raw()['default_group_ids'];
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_int')) : [];

        return $ids === [] ? [] : UserGroup::whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Everything a sign-in needs is present (an Entra web app always needs its secret). */
    public function configured(): bool
    {
        return $this->issuer() !== ''
            && $this->clientId() !== ''
            && ($this->provider() !== 'entra' || $this->clientSecret() !== null);
    }

    /** Show the "Login with SSO" button and accept sign-ins. */
    public function loginEnabled(): bool
    {
        return (bool) $this->raw()['enabled'] && $this->configured();
    }

    /** For the page shell: never let a settings read failure (database down) break the page itself. */
    public function showLoginButton(): bool
    {
        try {
            return $this->loginEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> API-safe view: no secret, only whether one is set. */
    public function publicView(): array
    {
        $c = $this->raw();

        return [
            'enabled' => (bool) $c['enabled'],
            'provider' => $this->provider(),
            'tenant' => (string) $c['tenant'],
            'issuer' => (string) $c['issuer'],
            'client_id' => (string) $c['client_id'],
            'client_secret_set' => $this->clientSecret() !== null,
            'role_claim' => (string) $c['role_claim'],
            'admin_role' => (string) $c['admin_role'],
            'operator_role' => (string) $c['operator_role'],
            'restrict_new_users' => $this->restrictNewUsers(),
            'default_group_ids' => $this->defaultGroupIds(),
            'configured' => $this->configured(),
        ];
    }

    /**
     * Save the admin's settings. A null/absent `client_secret` keeps the stored one, '' clears it,
     * anything else is (re)encrypted - so editing other fields never wipes the secret.
     *
     * @param  array<string,mixed>  $input  validated
     */
    public function save(array $input): void
    {
        $c = $this->raw();
        $secret = $c['client_secret'];
        if (array_key_exists('client_secret', $input) && $input['client_secret'] !== null) {
            $secret = $input['client_secret'] === '' ? null : Crypt::encryptString((string) $input['client_secret']);
        }

        Setting::updateOrCreate(['key' => self::KEY], [
            'type' => 'json',
            'value' => [
                'enabled' => (bool) $input['enabled'],
                'provider' => (string) $input['provider'],
                'tenant' => trim((string) ($input['tenant'] ?? '')),
                'issuer' => rtrim(trim((string) ($input['issuer'] ?? '')), '/'),
                'client_id' => trim((string) ($input['client_id'] ?? '')),
                'client_secret' => $secret,
                'role_claim' => trim((string) ($input['role_claim'] ?? '')) ?: 'roles',
                'admin_role' => trim((string) $input['admin_role']),
                'operator_role' => trim((string) $input['operator_role']),
                // Absent (an older client) keeps what's stored.
                'restrict_new_users' => (bool) ($input['restrict_new_users'] ?? $c['restrict_new_users']),
                'default_group_ids' => array_values(array_unique(array_map('intval', (array) ($input['default_group_ids'] ?? $c['default_group_ids'])))),
            ],
        ]);
    }
}
