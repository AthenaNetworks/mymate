<?php

namespace App\Services\Sso;

use App\Support\OutboundHostGuard;
use App\Support\SsoSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OpenID Connect authorization-code flow with PKCE, for any standard provider (Entra ID first).
 *
 * The ID token is checked for issuer, audience, expiry and nonce. Its signature is not: the token
 * comes straight from the provider's token endpoint over TLS, in exchange for a one-time code and
 * our client secret / PKCE verifier, which OpenID Connect Core 1.0 section 3.1.3.7 allows to stand
 * in for the signature check. That keeps this free of a JWT/JWKS dependency. Never feed it an ID
 * token that arrived any other way (e.g. from the browser).
 */
class OidcClient
{
    private const TIMEOUT = 8;

    public function __construct(private SsoSettings $settings) {}

    /**
     * The provider's discovery document (cached for an hour per issuer).
     *
     * @return array{issuer:string, authorization_endpoint:string, token_endpoint:string}
     */
    public function discovery(bool $fresh = false): array
    {
        $issuer = $this->settings->issuer();
        if ($issuer === '') {
            throw new SsoException('not_configured', 'SSO has no issuer / tenant set');
        }
        $key = 'sso.discovery.'.sha1($issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, function () use ($issuer): array {
            $url = $issuer.'/.well-known/openid-configuration';
            $this->guard($url);
            try {
                $doc = Http::timeout(self::TIMEOUT)->acceptJson()->get($url)->throw()->json();
            } catch (\Throwable $e) {
                throw new SsoException('provider_unreachable', "discovery failed for {$url}: ".$e->getMessage());
            }
            if (! is_array($doc) || ! is_string($doc['issuer'] ?? null) || ! is_string($doc['authorization_endpoint'] ?? null) || ! is_string($doc['token_endpoint'] ?? null)) {
                throw new SsoException('provider_invalid', "discovery document at {$url} is missing issuer / endpoints");
            }
            if (! $this->issuerMatches($issuer, $doc['issuer'])) {
                throw new SsoException('provider_invalid', "discovery issuer {$doc['issuer']} doesn't match {$issuer}");
            }

            return [
                'issuer' => rtrim($doc['issuer'], '/'),
                'authorization_endpoint' => $doc['authorization_endpoint'],
                'token_endpoint' => $doc['token_endpoint'],
            ];
        });
    }

    /** Where to send the browser to sign in. */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier): string
    {
        $endpoint = $this->discovery()['authorization_endpoint'];
        $query = http_build_query([
            'client_id' => $this->settings->clientId(),
            'response_type' => 'code',
            'response_mode' => 'query', // GET back to us: the SameSite=lax session cookie comes along
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Swap the one-time code for tokens and return the verified ID token claims.
     *
     * @return array<string,mixed>
     */
    public function exchange(string $code, string $redirectUri, string $codeVerifier, string $nonce): array
    {
        $doc = $this->discovery();
        $this->guard($doc['token_endpoint']);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->settings->clientId(),
            'code_verifier' => $codeVerifier,
        ];
        $secret = $this->settings->clientSecret();
        if ($secret !== null) {
            $form['client_secret'] = $secret;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->acceptJson()->asForm()->post($doc['token_endpoint'], $form);
        } catch (\Throwable $e) {
            throw new SsoException('provider_unreachable', 'token request failed: '.$e->getMessage());
        }
        if (! $response->successful()) {
            // The provider's error text (e.g. AADSTS7000215 - bad client secret) goes to the log only.
            throw new SsoException('token_failed', 'token endpoint returned '.$response->status().': '.mb_substr($response->body(), 0, 500));
        }
        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new SsoException('token_failed', 'token response had no id_token');
        }

        return $this->verifyIdToken($idToken, $doc['issuer'], $nonce);
    }

    /**
     * Decode + check an ID token received directly from the token endpoint (see class doc).
     *
     * @return array<string,mixed>
     */
    public function verifyIdToken(string $idToken, string $issuer, string $nonce): array
    {
        $parts = explode('.', $idToken);
        $claims = count($parts) === 3 ? json_decode(self::base64UrlDecode($parts[1]), true) : null;
        if (! is_array($claims)) {
            throw new SsoException('token_invalid', 'id_token is not a readable JWT');
        }

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new SsoException('token_invalid', 'id_token issuer '.($claims['iss'] ?? '(none)')." isn't {$issuer}");
        }
        $clientId = $this->settings->clientId();
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($clientId, $audiences, true) || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)) {
            throw new SsoException('token_invalid', 'id_token audience is not this app');
        }
        $now = time();
        $leeway = 120; // clock skew between us and the provider
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - $leeway) {
            throw new SsoException('token_invalid', 'id_token has expired');
        }
        if (is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] > $now + $leeway) {
            throw new SsoException('token_invalid', 'id_token was issued in the future');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SsoException('token_invalid', 'id_token nonce does not match this sign-in');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SsoException('token_invalid', 'id_token has no subject');
        }

        return $claims;
    }

    /**
     * The discovered issuer must be the configured one. Entra is the exception: an admin may give
     * the tenant as a domain (contoso.onmicrosoft.com) while the discovered issuer names the tenant
     * by its id - so accept any single-tenant Entra v2.0 issuer for an Entra configuration.
     */
    private function issuerMatches(string $configured, string $discovered): bool
    {
        if (rtrim($discovered, '/') === rtrim($configured, '/')) {
            return true;
        }

        return $this->settings->provider() === 'entra'
            && preg_match('#^https://login\.microsoftonline\.com/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/v2\.0/?$#i', $discovered) === 1;
    }

    /** Same SSRF guard as mail / webhooks: no loopback, link-local or metadata addresses. */
    private function guard(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || ! OutboundHostGuard::isSafeUrl($url)) {
            throw new SsoException('provider_invalid', "refusing to contact {$url} (must be https and not a loopback / link-local address)");
        }
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
