<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoException;
use App\Support\EngineLog;
use App\Support\SsoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single sign-on settings (admin, Settings > Security). The client secret is write-only: `show`
 * only says whether one is set. `callback_url` is the redirect URI to register at the provider.
 * `test` fetches the provider's discovery document so a typo shows up before anyone signs in.
 */
class SsoSettingController extends Controller
{
    public function show(SsoSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->view($settings)]);
    }

    public function update(Request $request, SsoSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in(SsoSettings::PROVIDERS)],
            // A tenant id (GUID) or verified domain - never Entra's shared multi-tenant endpoints.
            'tenant' => ['nullable', 'required_if:provider,entra', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/', Rule::notIn(SsoSettings::SHARED_TENANTS)],
            'issuer' => ['nullable', 'required_if:provider,oidc', 'string', 'max:500', 'url:https'],
            'client_id' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:2000'],
            'role_claim' => ['nullable', 'string', 'max:100'],
            'admin_role' => ['required', 'string', 'max:255'],
            'operator_role' => ['required', 'string', 'max:255', 'different:admin_role'],
            // Accounts SSO creates itself: start restricted, and join these groups.
            'restrict_new_users' => ['sometimes', 'boolean'],
            'default_group_ids' => ['sometimes', 'array', 'max:100'],
            'default_group_ids.*' => ['integer', 'distinct', 'exists:user_groups,id'],
        ], [
            'tenant.not_in' => 'Use your own tenant id or domain - the shared common / organizations / consumers endpoints would let any Microsoft account sign in.',
        ]);

        // An Entra web app can't sign anyone in without its secret: a blank field keeps the stored
        // one, '' clears it - so check what the secret will be after this save.
        $willHaveSecret = array_key_exists('client_secret', $data) && $data['client_secret'] !== null
            ? $data['client_secret'] !== ''
            : $settings->clientSecret() !== null;
        if ($data['enabled'] && $data['provider'] === 'entra' && ! $willHaveSecret) {
            throw ValidationException::withMessages(['client_secret' => 'Entra ID needs the app registration\'s client secret before SSO can be turned on.']);
        }

        $settings->save($data);
        Cache::forget('sso.discovery.'.sha1($settings->issuer())); // a changed provider is re-discovered

        EngineLog::warning('auth: sso settings changed', [
            'actor_id' => $request->user()->id,
            'enabled' => (bool) $data['enabled'],
            'provider' => $data['provider'],
            'restrict_new_users' => $settings->restrictNewUsers(),
            'default_group_ids' => $settings->defaultGroupIds(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => $this->view($settings)]);
    }

    public function test(SsoSettings $settings, OidcClient $oidc): JsonResponse
    {
        try {
            $doc = $oidc->discovery(fresh: true);
        } catch (SsoException $e) {
            return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'issuer' => $doc['issuer'], 'authorization_endpoint' => $doc['authorization_endpoint']]);
    }

    /** @return array<string,mixed> */
    private function view(SsoSettings $settings): array
    {
        return $settings->publicView() + ['callback_url' => route('sso.callback')];
    }
}
