<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * `php artisan mymate:configure-host mon.example.com` - point the instance at the
 * hostname the console is actually browsed from. Sanctum's SPA
 * cookie auth ignores the session unless the browsing host is a stateful domain, so a
 * fresh install reached by a name the installer didn't detect would 401 on /api. This
 * rewrites APP_URL / SANCTUM_STATEFUL_DOMAINS / CORS_ALLOWED_ORIGINS in .env.
 *
 * Pass --https to also switch APP_URL to https and enable the secure session cookie.
 */
class ConfigureHostCommand extends Command
{
    protected $signature = 'mymate:configure-host
        {host : The hostname (or IP) the console is served on, e.g. mon.example.com}
        {--https : Serve over HTTPS (sets https URL + SESSION_SECURE_COOKIE=true)}';

    protected $description = 'Point the instance at the hostname the console is browsed from (fixes SPA auth 401s).';

    public function handle(): int
    {
        $host = trim((string) $this->argument('host'));
        // Strip any scheme/path the operator may have pasted.
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0]);

        if ($host === '') {
            $this->error('Provide a hostname, e.g. mon.example.com');

            return self::FAILURE;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath) || ! is_writable($envPath)) {
            $this->error("Can't write {$envPath} - run this as the app user (sudo -u mymate ...).");

            return self::FAILURE;
        }

        $https = (bool) $this->option('https');
        $scheme = $https ? 'https' : 'http';
        $stateful = implode(',', array_unique([$host, 'localhost', '127.0.0.1', '::1']));

        $updates = [
            'APP_URL' => "{$scheme}://{$host}",
            'SANCTUM_STATEFUL_DOMAINS' => $stateful,
            'CORS_ALLOWED_ORIGINS' => "{$scheme}://{$host}",
            'SESSION_SECURE_COOKIE' => $https ? 'true' : 'false',
        ];

        $env = (string) file_get_contents($envPath);
        foreach ($updates as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $env = preg_match("/^{$key}=.*$/m", $env)
                ? preg_replace("/^{$key}=.*$/m", $line, $env)
                : rtrim($env)."\n".$line."\n";
        }
        file_put_contents($envPath, $env);

        $this->call('config:clear');

        $this->info("Configured for {$scheme}://{$host}. Restart the services to apply:");
        $this->line('  sudo systemctl restart mymate-reverb mymate-horizon mymate-loop && sudo systemctl reload nginx');

        return self::SUCCESS;
    }

    private function quote(string $value): string
    {
        // Quote if it contains characters the dotenv parser would choke on.
        return preg_match('/\s|#|"/', $value) ? '"'.addcslashes($value, '"\\').'"' : $value;
    }
}
