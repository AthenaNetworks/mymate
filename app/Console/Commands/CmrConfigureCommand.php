<?php

namespace App\Console\Commands;

use App\Support\CmrSettings;
use Illuminate\Console\Command;

/**
 * Point My Mate at the CMR controller sidecar without the Settings UI - used by
 * deploy/cmr/provision.sh and the Docker entrypoint. Saves the URL + token (encrypted) and
 * turns the sync on.
 *
 *   php artisan mymate:cmr:configure --url http://127.0.0.1:8411 --token-file /etc/mymate-cmr/admin.token
 */
class CmrConfigureCommand extends Command
{
    protected $signature = 'mymate:cmr:configure
        {--url= : The cmr-ctrl admin API base URL (e.g. http://127.0.0.1:8411)}
        {--token= : The admin API bearer token}
        {--token-file= : Read the token from this file instead of --token}
        {--disable : Turn the CMR sync off instead}';

    protected $description = 'Configure My Mate to talk to the CMR controller (cmr-ctrl)';

    public function handle(CmrSettings $settings): int
    {
        if ($this->option('disable')) {
            $settings->save(['enabled' => false]);
            $this->info('CMR sync turned off.');

            return self::SUCCESS;
        }

        $url = (string) ($this->option('url') ?: config('mymate.cmr.url'));
        $token = (string) $this->option('token');
        if ($token === '' && $this->option('token-file')) {
            $path = (string) $this->option('token-file');
            if (! is_readable($path)) {
                $this->error("Can't read the token file {$path}.");

                return self::FAILURE;
            }
            $token = trim((string) file_get_contents($path));
        }

        if ($url === '' || $token === '') {
            $this->error('Need a --url and a --token (or --token-file).');

            return self::FAILURE;
        }

        $settings->save(['enabled' => true, 'api_url' => $url, 'api_token' => $token]);
        $this->info("My Mate's CMR controller configured against {$url}.");

        return self::SUCCESS;
    }
}
