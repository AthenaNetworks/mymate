<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    // Creates an operator via actingAsUser(); without this it would COMMIT that user and
    // leak it into later transactional tests.
    use RefreshDatabase;

    public function test_public_health_endpoint_carries_security_headers(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self' ws: wss:", $csp);
    }

    public function test_authenticated_api_responses_carry_security_headers(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/user')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_only_sent_over_https(): void
    {
        // .env's APP_URL is https in this project, so the test client defaults to
        // https - force explicit schemes on both sides rather than relying on that.
        $this->getJson('http://localhost/api/health')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/api/health')->assertHeader('Strict-Transport-Security');
    }
}
