<?php

namespace Tests\Feature;

use App\Support\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupConfigureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_configures_the_backup_engine_from_url_and_token(): void
    {
        $this->artisan('mymate:backup:configure', ['--url' => 'http://127.0.0.1:8410', '--token' => 'abc123'])
            ->assertSuccessful();

        $settings = app(BackupSettings::class);
        $this->assertSame('http://127.0.0.1:8410', $settings->apiUrl());
        $this->assertSame('abc123', $settings->apiToken());
        $this->assertTrue($settings->configured());
    }

    public function test_reads_the_token_from_a_rusted_config_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rusted');
        file_put_contents($path, "db = \"x\"\napi_addr  = \"127.0.0.1:8410\"\napi_token = \"tok-from-file\"\nsecret = \"s\"\n");

        $this->artisan('mymate:backup:configure', ['--url' => 'http://127.0.0.1:8410', '--from-rusted' => $path])
            ->assertSuccessful();

        $this->assertSame('tok-from-file', app(BackupSettings::class)->apiToken());
        @unlink($path);
    }

    public function test_fails_without_a_token(): void
    {
        $this->artisan('mymate:backup:configure', ['--url' => 'http://127.0.0.1:8410'])
            ->assertFailed();
    }
}
