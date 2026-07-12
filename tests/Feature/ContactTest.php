<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Public "contact sales" form (sales demo) - no auth, validated, emails + logs the lead. */
class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_visitor_can_submit_a_lead(): void
    {
        Mail::fake(); // don't actually deliver
        config(['mymate.demo.contact_to' => 'sales@example.test']);

        $this->postJson('/api/contact', [
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'company' => 'Acme',
            'message' => 'We have ~400 devices - interested.',
        ])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_it_validates_required_fields(): void
    {
        $this->postJson('/api/contact', ['name' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'message']);
    }

    public function test_it_still_succeeds_when_no_delivery_address_is_configured(): void
    {
        Mail::fake();
        config(['mymate.demo.contact_to' => '']);

        $this->postJson('/api/contact', [
            'name' => 'Jane', 'email' => 'jane@acme.test', 'message' => 'Hi',
        ])->assertOk();

        Mail::assertNothingSent(); // logged only
    }
}
