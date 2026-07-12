<?php

namespace App\Actions\Backup;

use App\Models\Device;
use App\Services\Backup\RustedClient;
use App\Support\BackupSettings;
use App\Support\RustedDrivers;
use RuntimeException;

/**
 * Push (upsert) a device - and the SSH credential it should be backed up with - into the
 * Rusted engine. Idempotent: called before every backup run so Rusted's
 * inventory always matches My Mate's (name, host, driver, enabled flag, credential).
 *
 * Credential resolution ("reuse device creds + a Settings fallback", the chosen model):
 *  1. the device's own My Mate credential if it carries an SSH-usable username/password
 *     (RouterOS-API logins double as SSH logins) - pushed as `mymate-cred-{id}`;
 *  2. else the default SSH credential from Settings - pushed as `mymate-default`;
 *  3. else fail loudly - a backup can't run without a login.
 *
 * Secrets are only ever sent onward to the (localhost, trusted) Rusted API - never logged.
 */
class RegisterBackupDevice
{
    public function __construct(
        private RustedClient $client,
        private BackupSettings $settings,
    ) {}

    public function __invoke(Device $device): void
    {
        $driver = $device->backup_driver ?: RustedDrivers::suggestFor($device);
        if ($driver === null) {
            throw new RuntimeException("No backup driver for '{$device->name}' - set one before backing it up.");
        }

        [$credentialName, $credentialPayload] = $this->resolveCredential($device);
        $this->client->putCredential($credentialPayload);

        $this->client->putDevice([
            'name' => self::rustedName($device),
            'host' => $device->mgmt_ip,
            'port' => (int) config('mymate.backup.ssh_port', 22),
            'driver' => $driver,
            'credential' => $credentialName,
            'group' => (string) config('mymate.backup.group', 'mymate'),
            'enabled' => (bool) $device->backup_enabled,
        ]);
    }

    /** The stable Rusted device name for a My Mate device ("{prefix}{id}"). */
    public static function rustedName(Device $device): string
    {
        return config('mymate.backup.device_prefix', 'mymate-').$device->id;
    }

    /**
     * @return array{0: string, 1: array<string,mixed>} [credentialName, rustedCredentialPayload]
     */
    private function resolveCredential(Device $device): array
    {
        $cred = $device->credential;
        // Accessing username/password triggers the model's `encrypted` cast (decrypt).
        if ($cred !== null && (string) $cred->username !== '') {
            return ["mymate-cred-{$cred->id}", [
                'name' => "mymate-cred-{$cred->id}",
                'username' => (string) $cred->username,
                'password' => (string) $cred->password,
                'enable' => '',
                'private_key' => '',
            ]];
        }

        $fallback = $this->settings->defaultSshCredential();
        if ($fallback !== null) {
            return ['mymate-default', [
                'name' => 'mymate-default',
                'username' => $fallback['username'],
                'password' => $fallback['password'],
                'enable' => $fallback['enable'],
                'private_key' => '',
            ]];
        }

        throw new RuntimeException(
            "No SSH credential for '{$device->name}' - attach a RouterOS credential or set a default SSH login in Settings.",
        );
    }
}
