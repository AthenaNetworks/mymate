<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'noc@example.com',
            'password' => 'super-secret',
            'from_address' => 'alerts@example.com',
            'from_name' => 'My Mate NOC',
        ], $overrides);
    }

    public function test_show_returns_config_without_the_password(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/settings/mail')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.password_set', false)
            ->assertJsonMissingPath('data.password');
    }

    public function test_update_saves_encrypts_password_and_never_leaks_it(): void
    {
        $this->actingAsUser();

        $res = $this->putJson('/api/settings/mail', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.host', 'smtp.example.com')
            ->assertJsonPath('data.password_set', true)
            ->assertJsonPath('data.configured', true)
            ->assertJsonMissingPath('data.password');

        // The plaintext password is nowhere in the response.
        $this->assertStringNotContainsString('super-secret', json_encode($res->json()));

        // Stored encrypted (not plaintext) but decryptable back.
        $row = Setting::where('key', 'mail.smtp')->firstOrFail();
        $this->assertNotSame('super-secret', $row->value['password']);
        $this->assertSame('super-secret', Crypt::decryptString($row->value['password']));
    }

    public function test_update_without_a_password_keeps_the_existing_one(): void
    {
        $this->actingAsUser();
        $this->putJson('/api/settings/mail', $this->payload())->assertOk();

        // Edit another field, omit the password -> it must persist.
        $this->putJson('/api/settings/mail', $this->payload(['from_name' => 'Renamed', 'password' => '']))
            ->assertOk()
            ->assertJsonPath('data.from_name', 'Renamed')
            ->assertJsonPath('data.password_set', true);

        $row = Setting::where('key', 'mail.smtp')->firstOrFail();
        $this->assertSame('super-secret', Crypt::decryptString($row->value['password']));
    }

    public function test_apply_layers_the_smtp_config_onto_the_runtime_mailer(): void
    {
        $this->actingAsUser();
        $this->putJson('/api/settings/mail', $this->payload(['encryption' => 'ssl', 'port' => 465]))->assertOk();

        $mailer = app(MailSettings::class)->apply();

        $this->assertSame('smtp', $mailer);
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme')); // ssl -> implicit TLS
        $this->assertSame('super-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('alerts@example.com', config('mail.from.address'));
    }

    public function test_apply_returns_null_when_nothing_is_configured(): void
    {
        $this->assertNull(app(MailSettings::class)->apply());
    }

    public function test_validation_rejects_a_bad_encryption_and_missing_host(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/mail', $this->payload(['encryption' => 'rot13']))
            ->assertStatus(422)->assertJsonValidationErrors('encryption');
        $this->putJson('/api/settings/mail', $this->payload(['host' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('host');
    }

    public function test_test_endpoint_sends_through_the_mailer(): void
    {
        Mail::fake();
        $this->actingAsUser();

        $this->postJson('/api/settings/mail/test', ['to' => 'ops@example.com'])
            ->assertOk()->assertJsonPath('ok', true);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/settings/mail')->assertUnauthorized();
        $this->putJson('/api/settings/mail', $this->payload())->assertUnauthorized();
    }

    /**  SSRF hardening - save-time rejection of loopback/link-local/reserved targets. */
    public function test_rejects_a_loopback_or_link_local_host(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/mail', $this->payload(['host' => '127.0.0.1']))
            ->assertStatus(422)->assertJsonValidationErrors(['host']);

        $this->putJson('/api/settings/mail', $this->payload(['host' => '169.254.169.254']))
            ->assertStatus(422)->assertJsonValidationErrors(['host']);
    }

    /** An internal SMTP relay is a legitimate target - RFC1918 stays allowed. */
    public function test_still_allows_a_private_network_host(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/settings/mail', $this->payload(['host' => '10.0.0.25']))
            ->assertOk()->assertJsonPath('data.host', '10.0.0.25');
    }

    /**  - apply() re-checks at send-time and falls back to the default mailer if unsafe. */
    public function test_apply_refuses_a_host_that_is_no_longer_safe(): void
    {
        $this->actingAsUser();
        $this->putJson('/api/settings/mail', $this->payload(['host' => '10.0.0.25']))->assertOk();

        // Simulate a config row that bypassed validation (e.g. a legacy row) by writing directly.
        $row = Setting::where('key', 'mail.smtp')->firstOrFail();
        $row->update(['value' => array_merge($row->value, ['host' => '127.0.0.1'])]);

        $this->assertNull(app(MailSettings::class)->apply());
    }

    public function test_test_endpoint_is_rate_limited(): void
    {
        Mail::fake();
        $this->actingAsUser();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/settings/mail/test', ['to' => 'ops@example.com'])->assertOk();
        }

        $this->postJson('/api/settings/mail/test', ['to' => 'ops@example.com'])->assertStatus(429);
    }

    /**  - the raw SMTP exception text must never reach the client (was a port-scan oracle). */
    public function test_test_endpoint_never_leaks_the_raw_failure_reason(): void
    {
        $this->actingAsUser();
        $this->app->instance(MailSettings::class, new class extends MailSettings
        {
            public function sendTest(string $to): void
            {
                throw new \RuntimeException('Connection refused at 10.0.0.9:25 - a very specific banner detail');
            }
        });

        $res = $this->postJson('/api/settings/mail/test', ['to' => 'ops@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Test send failed.');

        $this->assertStringNotContainsString('10.0.0.9', json_encode($res->json()));
        $this->assertStringNotContainsString('banner detail', json_encode($res->json()));
    }
}
