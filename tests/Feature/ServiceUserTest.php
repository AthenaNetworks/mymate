<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every way of installing My Mate runs its processes as the `mymate` user, never root or
 * www-data. The Rusted and CMR sidecars are the exception: on a host they keep their own users
 * so a hole in the web app can't read their SSH keys or the CMR identity key.
 */
class ServiceUserTest extends TestCase
{
    /** @return array<string, string> program => user, for each [program:x] in a supervisor file */
    private function supervisorUsers(string $file): array
    {
        $users = [];
        $program = null;
        foreach (file(base_path($file), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\[(\w+):?([\w-]*)\]/', $line, $m)) {
                $program = $m[1] === 'program' ? $m[2] : null;
                if ($program !== null) {
                    $users[$program] = '(none)';
                }
            } elseif ($program !== null && preg_match('/^user=(\S+)/', $line, $m)) {
                $users[$program] = $m[1];
            }
        }

        return $users;
    }

    public function test_supervisor_templates_run_everything_as_mymate(): void
    {
        foreach (['deploy/supervisor/mymate.conf', 'deploy/supervisor/mymate-demo.conf'] as $file) {
            $users = $this->supervisorUsers($file);
            $this->assertNotEmpty($users, $file);
            foreach ($users as $program => $user) {
                $this->assertSame('mymate', $user, "$file [$program]");
            }
        }
    }

    public function test_the_container_runs_everything_but_nginx_and_php_fpm_master_as_mymate(): void
    {
        $users = $this->supervisorUsers('docker/supervisord.conf');
        // nginx and the php-fpm master start as root and drop their own workers: nginx to
        // www-data (docker/nginx.conf), php-fpm to mymate (docker/php-fpm-pool.conf).
        $this->assertSame('(none)', $users['nginx']);
        $this->assertSame('(none)', $users['php-fpm']);
        unset($users['nginx'], $users['php-fpm']);

        $this->assertEqualsCanonicalizing(
            ['rusted', 'cmr', 'reverb', 'horizon', 'loop', 'scheduler', 'agent-hub'],
            array_keys($users),
        );
        foreach ($users as $program => $user) {
            $this->assertSame('mymate', $user, "docker/supervisord.conf [$program]");
        }

        $pool = file_get_contents(base_path('docker/php-fpm-pool.conf'));
        $this->assertMatchesRegularExpression('/^user = mymate$/m', $pool);
        $this->assertMatchesRegularExpression('/^group = mymate$/m', $pool);
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $this->assertStringContainsString('COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-mymate.conf', $dockerfile);
        $this->assertStringContainsString('useradd --system --uid 990 --gid mymate', $dockerfile);
        $this->assertStringNotContainsString('www-data:www-data', $dockerfile);
        $this->assertStringNotContainsString('www-data:www-data', file_get_contents(base_path('docker/entrypoint.sh')));
    }

    public function test_packaged_units_run_as_mymate_and_sidecars_keep_their_own_users(): void
    {
        $units = glob(base_path('deploy/build/files/systemd/mymate-*.service'));
        $this->assertNotEmpty($units);
        foreach ($units as $unit) {
            if (basename($unit) === 'mymate-firstboot.service') {
                continue; // one-shot provisioning, runs as root on purpose
            }
            $this->assertMatchesRegularExpression('/^User=mymate$/m', file_get_contents($unit), basename($unit));
        }
        $this->assertMatchesRegularExpression('/^user = mymate$/m', file_get_contents(base_path('deploy/build/files/php-fpm-mymate.conf')));

        $this->assertMatchesRegularExpression('/^User=rusted$/m', file_get_contents(base_path('deploy/rusted/rusted-api.service')));
        $this->assertMatchesRegularExpression('/^User=mymate-cmr$/m', file_get_contents(base_path('deploy/cmr/mymate-cmr.service')));
    }

    public function test_log_files_are_group_writable(): void
    {
        foreach (['single', 'daily', 'mymate'] as $channel) {
            $this->assertSame(0664, config("logging.channels.$channel.permission"), $channel);
        }
    }
}
