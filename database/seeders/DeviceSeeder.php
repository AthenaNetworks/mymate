<?php

namespace Database\Seeders;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $user = env('MYMATE_DEVICE_USER');
        $pass = env('MYMATE_DEVICE_PASS');
        $community = env('MYMATE_SNMP_COMMUNITY');

        if (! $user || ! $pass || ! $community) {
            $this->command?->warn(
                'MYMATE_DEVICE_USER / MYMATE_DEVICE_PASS / MYMATE_SNMP_COMMUNITY not set in .env - '
                .'copy them from CREDS. Seeding devices without working credentials.'
            );
        }

        // One shared SNMP credential and one shared RouterOS credential (secrets encrypted on save).
        $snmp = Credential::create([
            'name' => 'Shared SNMP',
            'type' => 'snmp',
            'snmp_community' => $community,
            'api_port' => 8728,
        ]);

        $routeros = Credential::create([
            'name' => 'Shared RouterOS',
            'type' => 'routeros',
            'username' => $user,
            'password' => $pass,
            'api_port' => 8728,
        ]);

        // Non-secret device facts (IPs are documented in the PRD). poll_method per the
        // test-device matrix: BDR1 -> SNMP (its API port 8728 is filtered); others -> RouterOS API.
        $devices = [
            ['name' => 'BDR1', 'mgmt_ip' => '160.30.37.1', 'poll' => PollMethod::Snmp, 'cred' => $snmp],
            ['name' => 'DMZ1', 'mgmt_ip' => '10.178.0.254', 'poll' => PollMethod::RouterOs, 'cred' => $routeros],
            ['name' => 'CPE1', 'mgmt_ip' => '10.80.111.1', 'poll' => PollMethod::RouterOs, 'cred' => $routeros],
            ['name' => 'TEST', 'mgmt_ip' => '10.80.111.139', 'poll' => PollMethod::RouterOs, 'cred' => $routeros],
        ];

        foreach ($devices as $d) {
            Device::create([
                'name' => $d['name'],
                'mgmt_ip' => $d['mgmt_ip'],
                'poll_method' => $d['poll'],
                'credential_id' => $d['cred']->id,
                'status' => DeviceStatus::Unknown,
                'map_x' => 0,
                'map_y' => 0,
            ]);
        }
    }
}
