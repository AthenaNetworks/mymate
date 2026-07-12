<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SSL facility: with TLS terminated by a Cloudflare Tunnel or reverse proxy (methods 1 & 2),
 * the request reaches nginx->fpm over http carrying X-Forwarded-Proto: https. Laravel must
 * trust that header so it detects HTTPS (secure cookies, https URL generation).
 */
class TrustedProxyTest extends TestCase
{
    public function test_https_is_detected_from_the_forwarded_proto_header(): void
    {
        // An 'api/'-prefixed path so the SPA catch-all (^(?!api).*$) doesn't swallow it.
        Route::get('api/_ssl-probe', fn () => ['secure' => request()->isSecure()]);

        // Explicit http:// base so the assertion is independent of APP_URL: the forwarded
        // header alone must flip the request to secure (proving the proxy is trusted).
        $this->getJson('http://mon.example.com/api/_ssl-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertJson(['secure' => true]);
        $this->getJson('http://mon.example.com/api/_ssl-probe')
            ->assertJson(['secure' => false]);
    }
}
