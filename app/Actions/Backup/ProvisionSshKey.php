<?php

namespace App\Actions\Backup;

use App\Models\Credential;
use App\Models\Device;
use App\Services\Backup\RustedClient;
use RuntimeException;

/**
 * Bootstrap key-based SSH on a MikroTik so it can be backed up over SSH (RouterOS won't
 * hand a config back over the API). Uses the device's RouterOS-API login to have Rusted
 * install a generated key over the API, then stores the returned private key as a
 * dedicated SSH credential on the device and points its backups at the SSH driver.
 *
 * Returns the provisioning result (ssh port/enabled state) so the UI can report it -
 * notably whether Rusted had to enable the SSH service.
 */
class ProvisionSshKey
{
    public function __construct(private RustedClient $client) {}

    /** @return array{user:string, ssh_port:int, ssh_enabled:bool, ssh_enabled_by:bool} */
    public function __invoke(Device $device): array
    {
        $cred = $device->credential;
        if ($cred === null || $cred->type !== 'routeros' || (string) $cred->username === '') {
            throw new RuntimeException(
                "'{$device->name}' needs a RouterOS API credential (username + password) to bootstrap SSH over the API.",
            );
        }

        $result = $this->client->provisionMikrotikSshKey(
            $device->mgmt_ip,
            $cred->api_port ?: 8728,
            (string) $cred->username,
            (string) $cred->password,
        );

        // Store the private key as the device's SSH credential (reuse the existing one if
        // this device was provisioned before, so we don't leave orphans behind).
        $sshCred = $device->sshCredential;
        if ($sshCred === null || $sshCred->type !== 'ssh') {
            $sshCred = new Credential(['name' => "SSH (auto) - {$device->name} #{$device->id}"]);
        }
        $sshCred->type = 'ssh';
        // The "+ct" login suffix tells RouterOS to disable console colours and terminal
        // detection, so its SSH output is clean/script-friendly (without it the interactive
        // shell returns an empty capture). It still authenticates as the base user + key.
        $sshCred->username = $result['user'].'+ct';
        $sshCred->password = '';
        $sshCred->private_key = $result['private_key'];
        $sshCred->save();

        // Back this device up over SSH with the standard MikroTik driver from now on.
        $device->forceFill([
            'ssh_credential_id' => $sshCred->id,
            'backup_driver' => 'mikrotik_routeros',
        ])->save();

        // Don't hand the private key back to the browser.
        unset($result['private_key']);

        return $result;
    }
}
