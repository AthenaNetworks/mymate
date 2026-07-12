<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `mymate:configure-host` rewrites the auth-host keys in .env.
 * Runs against a throwaway base path so it never touches the real project .env.
 */
class ConfigureHostTest extends TestCase
{
    public function test_it_rewrites_the_auth_host_keys(): void
    {
        $dir = sys_get_temp_dir().'/mymate-cfg-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_URL=http://localhost\nSANCTUM_STATEFUL_DOMAINS=localhost\nSESSION_SECURE_COOKIE=false\n");

        $original = base_path();
        $this->app->setBasePath($dir);

        try {
            $this->artisan('mymate:configure-host', ['host' => 'mon.example.com', '--https' => true])
                ->assertOk();

            $env = (string) file_get_contents($dir.'/.env');
            $this->assertStringContainsString('APP_URL=https://mon.example.com', $env);
            $this->assertStringContainsString('mon.example.com', $env);
            $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $env);
            $this->assertStringContainsString('CORS_ALLOWED_ORIGINS=https://mon.example.com', $env);
        } finally {
            $this->app->setBasePath($original);
            @unlink($dir.'/.env');
            @rmdir($dir);
        }
    }

    public function test_it_strips_a_pasted_scheme_and_path(): void
    {
        $dir = sys_get_temp_dir().'/mymate-cfg-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', "APP_URL=http://localhost\n");

        $original = base_path();
        $this->app->setBasePath($dir);

        try {
            $this->artisan('mymate:configure-host', ['host' => 'http://mon.example.com/foo'])->assertOk();
            $env = (string) file_get_contents($dir.'/.env');
            $this->assertStringContainsString('APP_URL=http://mon.example.com', $env);
            $this->assertStringNotContainsString('/foo', $env);
        } finally {
            $this->app->setBasePath($original);
            @unlink($dir.'/.env');
            @rmdir($dir);
        }
    }
}
