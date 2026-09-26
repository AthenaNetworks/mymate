<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapShare;
use App\Models\User;
use App\Support\FrameAncestors;
use App\Support\WallEmbedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Embedding the public wallboard in an iframe (GitHub #15). The security-critical properties:
 * framing is only ever relaxed on the /wall/{token} page itself, only for the configured origins,
 * only while the share is live - and the logged-in app (and every API route) stays DENY.
 */
class WallEmbedTest extends TestCase
{
    use RefreshDatabase;

    private function share(bool $enabled = true): MapShare
    {
        $map = Map::factory()->create(['name' => 'NOC']);

        return MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => $enabled]);
    }

    private function allow(array $origins): void
    {
        app(WallEmbedSettings::class)->setFrameAncestors($origins);
    }

    private function frameAncestors(TestResponse $res): string
    {
        // Only our policy (the last one) carries frame-ancestors.
        $policies = $res->headers->all('content-security-policy');
        $mine = end($policies);
        preg_match('/frame-ancestors ([^;]+)/', (string) $mine, $m);

        return trim($m[1] ?? '');
    }

    private function assertDenied(TestResponse $res): void
    {
        $res->assertHeader('X-Frame-Options', 'DENY');
        $this->assertSame("'none'", $this->frameAncestors($res));
    }

    public function test_wallboard_stays_unframeable_until_an_admin_opts_in(): void
    {
        $share = $this->share();

        $this->assertDenied($this->get("/wall/{$share->token}")->assertOk());
    }

    public function test_wallboard_page_is_framable_by_configured_origins_only(): void
    {
        $share = $this->share();
        $this->allow(['https://intranet.example.com', 'https://*.dash.example.org:8443']);

        $res = $this->get("/wall/{$share->token}")->assertOk();

        // XFO can't do allow-lists; it must be gone or it'd override nothing but confuse old browsers.
        $res->assertHeaderMissing('X-Frame-Options');
        $this->assertSame('https://intranet.example.com https://*.dash.example.org:8443', $this->frameAncestors($res));
        $this->assertStringNotContainsString("'none'", $this->frameAncestors($res));
    }

    public function test_every_other_route_keeps_deny_even_with_origins_configured(): void
    {
        $share = $this->share();
        $this->allow(['https://intranet.example.com']);

        // The SPA shell and its client-side routes - framing the logged-in app is clickjacking.
        $this->assertDenied($this->get('/'));
        $this->assertDenied($this->get('/settings'));
        $this->assertDenied($this->get('/maps/1'));
        // Public API, including the wallboard's own data endpoints (XHRs are never framed).
        $this->assertDenied($this->getJson('/api/health'));
        $this->assertDenied($this->getJson("/api/public/wall/{$share->token}/map"));
        $this->assertDenied($this->getJson("/api/public/wall/{$share->token}/devices"));

        // A dead or unknown token 404s, and the 404 isn't framable either.
        $this->assertDenied($this->get('/wall/nosuchtoken')->assertNotFound());
        $off = $this->share(enabled: false);
        $this->assertDenied($this->get("/wall/{$off->token}")->assertNotFound());

        // Authenticated API.
        $this->actingAsUser();
        $this->assertDenied($this->getJson('/api/user')->assertOk());
    }

    public function test_config_default_applies_when_no_admin_setting_exists(): void
    {
        config(['mymate.wall.frame_ancestors' => 'https://a.example.com, not-an-origin https://b.example.com']);
        $share = $this->share();

        $res = $this->get("/wall/{$share->token}")->assertOk();
        // The bad env entry is dropped, never passed through into the header.
        $this->assertSame('https://a.example.com https://b.example.com', $this->frameAncestors($res));

        // An admin saving an empty list turns it back off, overriding the env default.
        $this->allow([]);
        $this->assertDenied($this->get("/wall/{$share->token}"));
    }

    public function test_admin_manages_the_allow_list_and_bad_origins_are_refused(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/wall-embed', ['frame_ancestors' => ['HTTPS://Intranet.Example.com/', 'http://10.0.0.5:8080', 'https://[::1]']])
            ->assertOk()
            ->assertJsonPath('data.frame_ancestors', ['https://intranet.example.com', 'http://10.0.0.5:8080', 'https://[::1]']);
        $this->getJson('/api/settings/wall-embed')->assertOk()->assertJsonCount(3, 'data.frame_ancestors');

        foreach ([
            '*', 'https://*', 'https://*.com', '*.example.com', "'self'", 'https:',
            'https://a.example.com; script-src *', 'https://a.example.com https://evil.test',
            'ftp://a.example.com', 'javascript://a.example.com', 'https://a.example.com/path',
            'https://a.example.com?x=1', 'https://user@a.example.com', 'https://a.example.com:99999',
            'https://a.*.example.com', 'https://-bad-.example.com', '',
        ] as $bad) {
            $this->putJson('/api/settings/wall-embed', ['frame_ancestors' => [$bad]])
                ->assertUnprocessable();
        }

        // Nothing bad got saved along the way.
        $this->getJson('/api/settings/wall-embed')->assertJsonCount(3, 'data.frame_ancestors');

        $this->putJson('/api/settings/wall-embed', ['frame_ancestors' => []])
            ->assertOk()->assertJsonPath('data.frame_ancestors', []);
    }

    public function test_non_admin_cannot_read_or_change_the_allow_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/settings/wall-embed')->assertForbidden();
        $this->putJson('/api/settings/wall-embed', ['frame_ancestors' => ['https://evil.test']])->assertForbidden();
        $this->assertSame([], app(WallEmbedSettings::class)->frameAncestors());
    }

    public function test_wildcard_rules(): void
    {
        $this->assertSame('https://*.example.com', FrameAncestors::normalise('https://*.example.com'));
        $this->assertNull(FrameAncestors::normalise('https://*.*.example.com'));
        $this->assertNull(FrameAncestors::normalise('https://*example.com'));
        $this->assertNull(FrameAncestors::normalise('https://*.10.0.0.1'));
    }

    public function test_wallboard_needs_no_session_or_csrf_so_it_works_in_a_third_party_iframe(): void
    {
        $share = $this->share();
        $this->allow(['https://intranet.example.com']);

        // The page itself sets no cookies at all - a cross-site iframe couldn't keep them anyway.
        $page = $this->get("/wall/{$share->token}")->assertOk();
        $this->assertSame([], $page->headers->getCookies());

        // The data endpoints work with no cookies and no CSRF token, even when the request looks
        // like it came from the SPA (which is what normally switches Sanctum's session on).
        $referer = ['Referer' => config('app.url').'/wall/'.$share->token, 'Origin' => config('app.url')];
        $api = $this->withHeaders($referer)->getJson("/api/public/wall/{$share->token}/map")->assertOk();
        $this->assertSame([], $api->headers->getCookies());
    }
}
