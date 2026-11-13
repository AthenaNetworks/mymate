<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Sso\OidcClient;
use App\Support\AuthSettings;
use App\Support\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Single sign-on (OpenID Connect, Entra ID first): the redirect + callback flow, ID token checks,
 * account linking, roles from the provider, disabling, the password-login rule for SSO-only
 * accounts, and the admin settings API.
 */
class SsoTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.test';

    private const CLIENT_ID = 'mymate-client';

    private const NONCE = 'nonce-123';

    private const VERIFIER = 'verifier-abcdefghijklmnopqrstuvwxyz-0123456789-abcdefghijklmnopq';

    private ?array $tokenClaims = null;

    private int $tokenStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function ($request) {
            $url = $request->url();
            if ($url === self::ISSUER.'/.well-known/openid-configuration') {
                return Http::response([
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER.'/authorize',
                    'token_endpoint' => self::ISSUER.'/token',
                ]);
            }
            if ($url === self::ISSUER.'/token') {
                return $this->tokenStatus === 200
                    ? Http::response(['id_token' => self::jwt($this->tokenClaims ?? []), 'access_token' => 'x', 'token_type' => 'Bearer'])
                    : Http::response(['error' => 'invalid_client'], $this->tokenStatus);
            }

            if (str_contains($url, 'nowhere.example.test')) {
                return Http::response('down', 503);
            }

            return null; // fall through to stubs a test adds itself
        });
    }

    private function enable(array $overrides = []): void
    {
        app(SsoSettings::class)->save(array_merge([
            'enabled' => true,
            'provider' => 'oidc',
            'tenant' => '',
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'top-secret',
            'role_claim' => 'roles',
            'admin_role' => 'MyMate.Admin',
            'operator_role' => 'MyMate.Operator',
        ], $overrides));
    }

    private static function jwt(array $claims): string
    {
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64($claims).'.c2ln';
    }

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'pairwise-sub',
            'oid' => '11111111-2222-3333-4444-555555555555',
            'exp' => time() + 3600,
            'iat' => time(),
            'nonce' => self::NONCE,
            'name' => 'Pat Example',
            'preferred_username' => 'pat@yourco.com',
            'roles' => ['MyMate.Operator'],
        ], $overrides);
    }

    /** Come back from the provider with a code, as if this browser had started the sign-in. */
    private function ssoCallback(array $claims, array $attempt = [], array $query = []): TestResponse
    {
        $this->tokenClaims = $claims;
        $attempt = array_merge(['state' => 'state-1', 'nonce' => self::NONCE, 'verifier' => self::VERIFIER, 'at' => time()], $attempt);

        return $this->withSession(['sso.attempt' => $attempt])
            ->get('/auth/sso/callback?'.http_build_query(array_merge(['code' => 'one-time-code', 'state' => 'state-1'], $query)));
    }

    public function test_routes_404_while_sso_is_off(): void
    {
        $this->get('/auth/sso/redirect')->assertNotFound();
        $this->get('/auth/sso/callback?code=x&state=y')->assertNotFound();
    }

    public function test_redirect_sends_the_browser_to_the_provider_with_state_nonce_and_pkce(): void
    {
        $this->enable();

        $response = $this->get('/auth/sso/redirect')->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $attempt = session('sso.attempt');
        $this->assertSame(self::CLIENT_ID, $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame(route('sso.callback'), $q['redirect_uri']);
        $this->assertSame($attempt['state'], $q['state']);
        $this->assertSame($attempt['nonce'], $q['nonce']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(OidcClient::challenge($attempt['verifier']), $q['code_challenge']);
    }

    public function test_first_sign_in_with_the_operator_role_creates_a_passwordless_operator(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims())->assertRedirect('/');

        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->hasPassword());
        $this->assertSame('Pat Example', $user->name);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $user->sso_subject); // Entra oid, not sub
        $this->assertNotNull($user->sso_last_login_at);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('sso', true)->assertJsonPath('has_password', false);

        // The token exchange sent the code, the PKCE verifier and the secret.
        Http::assertSent(fn ($r) => $r->url() === self::ISSUER.'/token'
            && $r['code'] === 'one-time-code' && $r['code_verifier'] === self::VERIFIER && $r['client_secret'] === 'top-secret');
    }

    public function test_the_admin_role_makes_an_administrator_and_losing_it_demotes(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims(['roles' => ['MyMate.Admin']]))->assertRedirect('/');
        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertTrue($user->is_admin);

        auth()->guard('web')->logout();
        $this->ssoCallback($this->claims(['roles' => ['MyMate.Operator']]))->assertRedirect('/');
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_no_role_refuses_and_creates_nothing(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims(['roles' => ['Something.Else']]))->assertRedirect('/?sso_error=no_role');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_losing_the_role_disables_the_account_and_getting_it_back_restores_it(): void
    {
        $this->enable();
        $this->ssoCallback($this->claims())->assertRedirect('/');
        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        auth()->guard('web')->logout();

        $this->ssoCallback($this->claims(['roles' => []]))->assertRedirect('/?sso_error=no_role');
        $user->refresh();
        $this->assertNotNull($user->disabled_at);
        $this->assertSame('sso', $user->disabled_reason);

        $this->ssoCallback($this->claims())->assertRedirect('/');
        $this->assertNull($user->fresh()->disabled_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_admin_disable_is_not_lifted_by_sso(): void
    {
        $this->enable();
        $this->ssoCallback($this->claims())->assertRedirect('/');
        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        auth()->guard('web')->logout();
        $user->forceFill(['disabled_at' => now(), 'disabled_reason' => 'admin'])->save();

        $this->ssoCallback($this->claims())->assertRedirect('/?sso_error=disabled');
        $this->assertGuest();
    }

    public function test_an_existing_local_account_is_linked_by_email_and_then_matched_by_identity(): void
    {
        $this->enable();
        $local = User::factory()->create(['email' => 'pat@yourco.com', 'password' => 'Local-password-1']);

        $this->ssoCallback($this->claims(['preferred_username' => 'PAT@yourco.com']))->assertRedirect('/');
        $local->refresh();
        $this->assertAuthenticatedAs($local);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $local->sso_subject);
        $this->assertTrue($local->hasPassword()); // linking keeps the password
        $this->assertSame(1, User::count());

        // The email changes at the provider: still the same account (matched by oid), email follows.
        auth()->guard('web')->logout();
        $this->ssoCallback($this->claims(['preferred_username' => 'pat.example@yourco.com']))->assertRedirect('/');
        $this->assertAuthenticatedAs($local);
        $this->assertSame('pat.example@yourco.com', $local->fresh()->email);
        $this->assertSame(1, User::count());
    }

    public function test_an_email_already_linked_to_another_identity_is_refused(): void
    {
        $this->enable();
        User::factory()->create(['email' => 'pat@yourco.com'])
            ->forceFill(['sso_issuer' => self::ISSUER, 'sso_subject' => 'someone-else'])->save();

        $this->ssoCallback($this->claims())->assertRedirect('/?sso_error=email_conflict');
        $this->assertGuest();
    }

    public function test_a_provider_that_says_the_email_is_unverified_cannot_create_or_link(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims(['email' => 'pat@yourco.com', 'email_verified' => false, 'preferred_username' => null]))
            ->assertRedirect('/?sso_error=no_email');
        $this->assertSame(0, User::count());
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function badTokens(): array
    {
        return [
            'another issuer' => [['iss' => 'https://evil.example.test']],
            'another audience' => [['aud' => 'some-other-app']],
            'expired' => [['exp' => time() - 3600]],
            'wrong nonce' => [['nonce' => 'replayed']],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_a_bad_id_token_is_refused(array $override): void
    {
        $this->enable();

        $this->ssoCallback($this->claims($override))->assertRedirect('/?sso_error=token_invalid');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_callback_this_browser_did_not_start_is_refused(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims(), query: ['state' => 'forged'])->assertRedirect('/?sso_error=expired');
        $this->ssoCallback($this->claims(), attempt: ['at' => time() - 3600])->assertRedirect('/?sso_error=expired');
        $this->tokenClaims = $this->claims();
        $this->get('/auth/sso/callback?code=x&state=state-1')->assertRedirect('/?sso_error=expired'); // no attempt in session
        $this->assertGuest();
    }

    public function test_provider_errors_come_back_as_readable_reasons(): void
    {
        $this->enable();

        $this->ssoCallback($this->claims(), query: ['error' => 'access_denied', 'code' => null])->assertRedirect('/?sso_error=provider_denied');

        $this->tokenStatus = 401;
        $this->ssoCallback($this->claims())->assertRedirect('/?sso_error=token_failed');
        $this->assertGuest();
    }

    public function test_provider_mfa_satisfies_a_required_passkey_and_without_it_the_rule_applies(): void
    {
        $this->enable();
        app(AuthSettings::class)->setPasskeyRequired(true);

        // The SPA's own requests carry its origin, so Sanctum runs them on the session - as a browser would.
        $this->withHeader('Referer', 'http://localhost/');

        $this->ssoCallback($this->claims(['amr' => ['pwd', 'mfa']]))->assertRedirect('/');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('passkey_stage', 'verified');
        $this->getJson('/api/devices')->assertOk();

        $this->post('/logout');
        $this->ssoCallback($this->claims(['amr' => ['pwd']]))->assertRedirect('/');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('passkey_stage', 'enrol');
        $this->getJson('/api/devices')->assertStatus(423);
    }

    public function test_entra_tenant_by_domain_accepts_the_tenant_id_issuer(): void
    {
        $tid = '9b1c7c2a-1111-2222-3333-444455556666';
        Http::fake([
            'https://login.microsoftonline.com/yourco.onmicrosoft.com/v2.0/.well-known/openid-configuration' => Http::response([
                'issuer' => "https://login.microsoftonline.com/{$tid}/v2.0",
                'authorization_endpoint' => "https://login.microsoftonline.com/{$tid}/oauth2/v2.0/authorize",
                'token_endpoint' => "https://login.microsoftonline.com/{$tid}/oauth2/v2.0/token",
            ]),
            "https://login.microsoftonline.com/{$tid}/oauth2/v2.0/token" => fn () => Http::response(['id_token' => self::jwt($this->claims(['iss' => "https://login.microsoftonline.com/{$tid}/v2.0", 'tid' => $tid]))]),
        ]);
        $this->enable(['provider' => 'entra', 'tenant' => 'yourco.onmicrosoft.com', 'issuer' => '']);

        $this->withSession(['sso.attempt' => ['state' => 's', 'nonce' => self::NONCE, 'verifier' => self::VERIFIER, 'at' => time()]])
            ->get('/auth/sso/callback?code=c&state=s')->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertSame("https://login.microsoftonline.com/{$tid}/v2.0", User::firstOrFail()->sso_issuer);
    }

    public function test_an_account_without_a_password_cannot_use_the_password_login(): void
    {
        $user = User::factory()->create(['email' => 'sso@yourco.com']);
        $user->forceFill(['password' => null])->save();

        $this->postJson('/login', ['email' => 'sso@yourco.com', 'password' => ''])->assertStatus(422);
        $this->postJson('/login', ['email' => 'sso@yourco.com', 'password' => 'anything'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_sign_in_and_loses_its_session_and_api_keys(): void
    {
        $user = User::factory()->create(['email' => 'op@yourco.com', 'password' => 'Correct-horse-1']);
        $token = $user->createToken('script')->plainTextToken;

        $this->actingAs($user, 'web');
        $this->getJson('/api/user')->assertOk();

        $user->forceFill(['disabled_at' => now(), 'disabled_reason' => 'admin'])->save();
        $this->getJson('/api/user')->assertUnauthorized()->assertJsonPath('code', 'account_disabled');
        $this->assertGuest('web');

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized()->assertJsonPath('code', 'account_disabled');

        // And the password login refuses it (withToken() would otherwise ride along on this request).
        app('auth')->forgetGuards();
        $this->withoutToken()->postJson('/login', ['email' => 'op@yourco.com', 'password' => 'Correct-horse-1'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_admin_can_disable_and_enable_an_operator_but_not_themselves_or_the_last_admin(): void
    {
        $admin = $this->actingAsUser();
        $op = User::factory()->create();

        $this->putJson("/api/users/{$op->id}", ['disabled' => true])->assertOk()->assertJsonPath('disabled', true)->assertJsonPath('disabled_reason', 'admin');
        $this->assertNotNull($op->fresh()->disabled_at);
        $this->putJson("/api/users/{$op->id}", ['disabled' => false])->assertOk()->assertJsonPath('disabled', false);

        $this->putJson("/api/users/{$admin->id}", ['disabled' => true])->assertStatus(422)->assertJsonValidationErrors('disabled');

        // Another admin, but disabled: the signed-in admin is still the only working one.
        $other = User::factory()->admin()->create();
        $other->forceFill(['disabled_at' => now(), 'disabled_reason' => 'admin'])->save();
        $this->putJson("/api/users/{$admin->id}", ['is_admin' => false])->assertStatus(422);
    }

    public function test_settings_are_admin_only_and_never_return_the_secret(): void
    {
        $this->actingAsUser();
        $this->enable();

        $this->getJson('/api/settings/sso')->assertOk()
            ->assertJsonPath('data.client_secret_set', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.callback_url', route('sso.callback'))
            ->assertJsonMissingPath('data.client_secret');
        $raw = json_encode(Setting::where('key', 'auth.sso')->first()->value);
        $this->assertStringNotContainsString('top-secret', $raw); // encrypted at rest

        // A blank secret keeps the stored one.
        $this->putJson('/api/settings/sso', ['enabled' => true, 'provider' => 'oidc', 'issuer' => self::ISSUER, 'client_id' => 'new-id', 'client_secret' => null, 'admin_role' => 'A', 'operator_role' => 'O'])
            ->assertOk()->assertJsonPath('data.client_id', 'new-id')->assertJsonPath('data.client_secret_set', true);
        $this->assertSame('top-secret', app(SsoSettings::class)->clientSecret());

        $viewer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($viewer)->getJson('/api/settings/sso')->assertForbidden();
        $this->actingAs($viewer)->putJson('/api/settings/sso', [])->assertForbidden();
    }

    public function test_settings_validation(): void
    {
        $this->actingAsUser();
        $base = ['enabled' => true, 'provider' => 'entra', 'tenant' => 'yourco.onmicrosoft.com', 'client_id' => 'id', 'admin_role' => 'A', 'operator_role' => 'O'];

        // Entra's shared endpoints would let any Microsoft account in.
        $this->putJson('/api/settings/sso', [...$base, 'tenant' => 'common', 'client_secret' => 's'])->assertStatus(422)->assertJsonValidationErrors('tenant');
        // Entra can't be switched on without its secret.
        $this->putJson('/api/settings/sso', $base)->assertStatus(422)->assertJsonValidationErrors('client_secret');
        // Generic OIDC needs an https issuer.
        $this->putJson('/api/settings/sso', [...$base, 'provider' => 'oidc', 'tenant' => null, 'issuer' => 'http://idp.example.test'])->assertStatus(422)->assertJsonValidationErrors('issuer');
        // The two roles must differ.
        $this->putJson('/api/settings/sso', [...$base, 'client_secret' => 's', 'operator_role' => 'A'])->assertStatus(422)->assertJsonValidationErrors('operator_role');

        $this->putJson('/api/settings/sso', [...$base, 'client_secret' => 's'])->assertOk()->assertJsonPath('data.configured', true);
    }

    public function test_the_settings_test_reaches_the_provider(): void
    {
        $this->actingAsUser();
        $this->enable();

        $this->postJson('/api/settings/sso/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('issuer', self::ISSUER);

        $this->enable(['issuer' => 'https://nowhere.example.test']);
        $this->postJson('/api/settings/sso/test')->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('reason', 'provider_unreachable');
    }

    private function group(string $name, array $maps = []): UserGroup
    {
        $group = new UserGroup(['name' => $name]);
        $group->restricted = true;
        $group->save();
        $group->maps()->attach(array_map(fn (Map $m) => $m->id, $maps));

        return $group;
    }

    public function test_an_admin_can_add_an_sso_only_account_with_no_password(): void
    {
        $this->actingAsUser();
        $this->enable(['enabled' => false]); // set up but not switched on yet - still allowed
        $map = Map::factory()->create();

        $this->postJson('/api/users', [
            'name' => 'Pat', 'email' => 'pat@yourco.com', 'sso_only' => true,
            'is_admin' => true, // ignored: the role comes from the provider at sign-in
            'restricted' => true, 'map_ids' => [$map->id],
        ])->assertCreated()->assertJsonPath('has_password', false)->assertJsonPath('sso', false)->assertJsonPath('is_admin', false);

        $pat = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertNull($pat->getRawOriginal('password')); // null, never a hash of ''
        $this->assertFalse($pat->is_admin);
        $this->assertTrue($pat->restricted);

        // No password, so the password form can't be used for it.
        auth()->guard('web')->logout();
        $this->postJson('/login', ['email' => 'pat@yourco.com', 'password' => 'Anything-123'])->assertStatus(422);
    }

    public function test_sso_only_needs_sso_set_up_and_otherwise_a_password_is_still_required(): void
    {
        $this->actingAsUser();

        // SSO not set up: refused.
        $this->postJson('/api/users', ['name' => 'Pat', 'email' => 'pat@yourco.com', 'sso_only' => true])
            ->assertStatus(422)->assertJsonValidationErrors('sso_only');

        $this->enable();
        // Not SSO-only: a password is still required.
        $this->postJson('/api/users', ['name' => 'Pat', 'email' => 'pat@yourco.com'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        // SSO-only with a password makes no sense.
        $this->postJson('/api/users', ['name' => 'Pat', 'email' => 'pat@yourco.com', 'sso_only' => true, 'password' => 'Strong-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame(0, User::where('email', 'pat@yourco.com')->count());
    }

    public function test_a_prepared_account_is_linked_on_first_sign_in_and_keeps_its_access(): void
    {
        $this->enable(['default_group_ids' => [$this->group('Default')->id]]);
        $map = Map::factory()->create();
        $own = $this->group('Field team', [$map]);
        $prepared = User::factory()->create(['email' => 'pat@yourco.com', 'restricted' => false]);
        $prepared->forceFill(['password' => null])->save();
        $prepared->groups()->attach($own->id);

        $this->ssoCallback($this->claims(['preferred_username' => 'Pat@YourCo.com']))->assertRedirect('/');

        $prepared->refresh();
        $this->assertAuthenticatedAs($prepared);
        $this->assertSame(1, User::count());
        $this->assertFalse($prepared->restricted); // "new users start restricted" is for accounts SSO creates
        $this->assertSame([$own->id], $prepared->groups()->pluck('user_groups.id')->all()); // no default groups added
        $this->assertSame([$map->id], $prepared->visibleMapIds());
    }

    public function test_a_new_sso_operator_starts_restricted_and_joins_the_default_groups(): void
    {
        $map = Map::factory()->create();
        Map::factory()->create(); // another map they must not see
        $default = $this->group('Default', [$map]);
        $this->enable(['restrict_new_users' => true, 'default_group_ids' => [$default->id]]);

        $this->ssoCallback($this->claims())->assertRedirect('/');

        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertTrue($user->restricted);
        $this->assertSame([$default->id], $user->groups()->pluck('user_groups.id')->all());
        $this->assertSame([$map->id], $user->visibleMapIds());
    }

    public function test_a_new_sso_operator_is_unrestricted_when_the_setting_is_off(): void
    {
        $this->enable(['restrict_new_users' => false]);

        $this->ssoCallback($this->claims())->assertRedirect('/');

        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertFalse($user->restricted);
        $this->assertSame([], $user->groups()->pluck('user_groups.id')->all());
    }

    public function test_a_new_sso_admin_is_never_restricted_or_put_in_default_groups(): void
    {
        $this->enable(['restrict_new_users' => true, 'default_group_ids' => [$this->group('Default')->id]]);

        $this->ssoCallback($this->claims(['roles' => ['MyMate.Admin']]))->assertRedirect('/');

        $user = User::where('email', 'pat@yourco.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertFalse($user->restricted);
        $this->assertSame(0, $user->groups()->count());
    }

    public function test_new_user_settings_save_and_a_deleted_group_drops_out(): void
    {
        $this->actingAsUser();
        $a = $this->group('A');
        $b = $this->group('B');
        $base = ['enabled' => true, 'provider' => 'oidc', 'issuer' => self::ISSUER, 'client_id' => 'id', 'admin_role' => 'A', 'operator_role' => 'O'];

        // Starts restricted by default.
        $this->getJson('/api/settings/sso')->assertOk()->assertJsonPath('data.restrict_new_users', true)->assertJsonPath('data.default_group_ids', []);

        $this->putJson('/api/settings/sso', [...$base, 'restrict_new_users' => false, 'default_group_ids' => [$b->id, $a->id]])
            ->assertOk()->assertJsonPath('data.restrict_new_users', false)->assertJsonPath('data.default_group_ids', [$a->id, $b->id]);
        // A client that doesn't send them keeps what's stored.
        $this->putJson('/api/settings/sso', $base)->assertOk()->assertJsonPath('data.restrict_new_users', false);

        $this->putJson('/api/settings/sso', [...$base, 'default_group_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('default_group_ids.0');

        $b->delete();
        $this->getJson('/api/settings/sso')->assertJsonPath('data.default_group_ids', [$a->id]);
    }

    public function test_the_login_page_only_offers_sso_when_it_is_on(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertDontSee('mymate:sso', false);

        $this->enable();
        $this->get('/')->assertOk()->assertSee('<meta name="mymate:sso" content="1">', false);

        $this->enable(['enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('mymate:sso', false);
    }
}
