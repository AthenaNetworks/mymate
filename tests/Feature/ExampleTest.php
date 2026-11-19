<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * The page carries the Reverb key the server is configured with, so the live socket always
     * uses the one Reverb expects - not one fixed into the frontend at build time, which in the
     * Docker image didn't match and every live update was refused.
     */
    public function test_the_page_tells_the_browser_which_reverb_key_to_use(): void
    {
        config(['broadcasting.connections.reverb.key' => 'site-key-123']);

        $this->get('/')->assertOk()->assertSee('<meta name="mymate:reverb-key" content="site-key-123">', false);
    }
}
