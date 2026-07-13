<?php

namespace App\Console\Commands;

use App\Support\BackupSettings;
use Illuminate\Console\Command;

/**
 * Point My Mate at the Rusted backup engine without touching the Settings UI - used by
 * the provisioning script (deploy/rusted/provision.sh) so a fresh install has working
 * backups out of the box. Stores the URL + token in the encrypted settings row.
 *
 *   php artisan mymate:backup:configure --url http://127.0.0.1:8410 --token <token>
 *   php artisan mymate:backup:configure --url http://127.0.0.1:8410 --from-rusted /etc/rusted/config.toml
 */
class BackupConfigureCommand extends Command
{
    protected $signature = 'mymate:backup:configure
        {--url= : The Rusted API base URL (e.g. http://127.0.0.1:8410)}
        {--token= : The Rusted API bearer token}
        {--from-rusted= : Read the token from a rusted config.toml instead of --token}';

    protected $description = 'Configure My Mate to talk to the Rusted backup engine';

    public function handle(BackupSettings $settings): int
    {
        $url = (string) ($this->option('url') ?: config('mymate.backup.url'));
        $token = (string) $this->option('token');

        if ($token === '' && $this->option('from-rusted')) {
            $token = $this->tokenFromConfig((string) $this->option('from-rusted'));
        }

        if ($url === '' || $token === '') {
            $this->error('Need a --url and a --token (or --from-rusted <config.toml>).');

            return self::FAILURE;
        }

        // Preserve any existing SSH-default fields; only (re)write url + token.
        $current = $settings->publicView();
        $settings->save([
            'api_url' => $url,
            'timeout' => $current['timeout'],
            'ssh_username' => $current['ssh_username'],
            'api_token' => $token,
        ]);

        $this->info("My Mate backups configured against {$url}.");

        return self::SUCCESS;
    }

    /** Pull `api_token = "..."` out of a rusted config.toml. */
    private function tokenFromConfig(string $path): string
    {
        if (! is_readable($path)) {
            $this->error("Can't read rusted config at {$path}.");

            return '';
        }

        foreach (file($path) ?: [] as $line) {
            if (preg_match('/^\s*api_token\s*=\s*"([^"]+)"/', $line, $m) === 1) {
                return $m[1];
            }
        }

        $this->error("No api_token found in {$path}.");

        return '';
    }
}
