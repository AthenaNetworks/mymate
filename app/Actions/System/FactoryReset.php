<?php

namespace App\Actions\System;

use App\Models\User;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * Wipe every scrap of monitoring data - devices, interfaces, links, maps, credentials,
 * agents, alerting, sensors, history - and keep only the admin account(s), returning the
 * install to a fresh-out-of-the-box state. Infrastructure tables (migrations, cache, queue,
 * settings, sessions/tokens) are left intact so the app keeps running and the admin stays
 * logged in. Deliberately destructive: guard every caller behind an admin + confirmation.
 */
class FactoryReset
{
    /**
     * Operational tables cleared on reset. TRUNCATE ... CASCADE also empties any table with a
     * foreign key into one of these, so the list only needs the roots; RESTART IDENTITY resets
     * the id sequences so the fresh install counts from 1 again.
     */
    private const TABLES = [
        'agents',
        'alert_events',
        'alert_policies',
        'alert_policy_transport',
        'alert_transports',
        'credentials',
        'device_map_positions',
        'device_metric_samples',
        'devices',
        'discovery_candidates',
        'import_runs',
        'interface_samples',
        'interfaces',
        'links',
        'maintenance_windows',
        'map_link_positions',
        'maps',
        'outages',
        'ping_samples',
        'routeros_packages',
        'sensor_readings',
        'sensor_samples',
        'sensors',
        'subnets',
    ];

    public function __invoke(): void
    {
        DB::transaction(function (): void {
            DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');

            // Operators are wiped with everything else; only admin accounts survive so whoever
            // triggered the reset stays able to log in and rebuild the fleet.
            User::query()->where('is_admin', false)->delete();
        });

        EngineLog::warning('factory reset: all monitoring data cleared, admin accounts retained');
    }
}
