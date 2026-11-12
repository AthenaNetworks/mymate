<?php

namespace App\Http\Controllers;

use App\Actions\Auth\ResolveSsoUser;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoException;
use App\Support\EngineLog;
use App\Support\SsoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Login with SSO" (OpenID Connect). Both steps are full-page browser navigations on the web
 * group, so the session carries the per-attempt state / nonce / PKCE verifier between them.
 *
 *  - redirect: start a sign-in and send the browser to the provider.
 *  - callback: the provider sends the browser back with a one-time code; we exchange it, resolve
 *    the operator (ResolveSsoUser) and start a normal session, exactly like a password login.
 *
 * Any failure lands back on the login page with `?sso_error=<reason>` (a fixed code - the detail
 * goes to the engine log only).
 */
class SsoController extends Controller
{
    private const SESSION_KEY = 'sso.attempt';

    private const MAX_AGE = 600; // a sign-in left at the provider for over 10 minutes must start again

    public function redirect(Request $request, SsoSettings $settings, OidcClient $oidc): RedirectResponse
    {
        abort_unless($settings->loginEnabled(), 404);

        $attempt = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'at' => time(),
        ];

        try {
            $url = $oidc->authorizationUrl($this->callbackUrl(), $attempt['state'], $attempt['nonce'], $attempt['verifier']);
        } catch (SsoException $e) {
            return $this->fail($request, $e);
        }
        $request->session()->put(self::SESSION_KEY, $attempt);

        return redirect()->away($url);
    }

    public function callback(Request $request, SsoSettings $settings, OidcClient $oidc, ResolveSsoUser $resolve): RedirectResponse
    {
        abort_unless($settings->loginEnabled(), 404);

        // One use only: whatever happens next, this attempt is spent.
        $attempt = $request->session()->pull(self::SESSION_KEY);

        try {
            if ($request->filled('error')) {
                // The provider refused (user cancelled, not assigned to the app, consent...).
                throw new SsoException('provider_denied', 'provider returned '.$request->query('error').': '.mb_substr((string) $request->query('error_description'), 0, 300));
            }
            if (! is_array($attempt) || ! is_string($request->query('state')) || ! hash_equals((string) $attempt['state'], $request->query('state'))) {
                throw new SsoException('expired', 'callback state does not match a sign-in started in this browser');
            }
            if (time() - (int) $attempt['at'] > self::MAX_AGE) {
                throw new SsoException('expired', 'sign-in took longer than '.self::MAX_AGE.'s');
            }
            if (! is_string($request->query('code')) || $request->query('code') === '') {
                throw new SsoException('provider_denied', 'callback had no code');
            }

            $claims = $oidc->exchange($request->query('code'), $this->callbackUrl(), (string) $attempt['verifier'], (string) $attempt['nonce']);
            $user = $resolve($claims);
        } catch (SsoException $e) {
            return $this->fail($request, $e);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // prevent session fixation, as the password login does

        // Second factor: the provider's MFA counts when the token says it happened (`amr`), so a
        // required passkey isn't asked for on top. Without it, the passkey rule applies as usual.
        $amr = $claims['amr'] ?? [];
        if (is_array($amr) && in_array('mfa', $amr, true)) {
            $request->session()->put('passkey_verified', true);
        }

        return redirect('/');
    }

    /** The redirect URI registered at the provider. Built from the address people use (trusted proxies). */
    public function callbackUrl(): string
    {
        return route('sso.callback');
    }

    private function fail(Request $request, SsoException $e): RedirectResponse
    {
        EngineLog::warning('auth: sso sign-in failed', ['reason' => $e->reason, 'detail' => $e->getMessage(), 'ip' => $request->ip()]);

        return redirect('/?sso_error='.rawurlencode($e->reason));
    }
}
