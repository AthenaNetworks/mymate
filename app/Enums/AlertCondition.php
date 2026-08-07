<?php

namespace App\Enums;

/**
 * What an alert policy fires on. The evaluator reconciles the set
 * of currently-active conditions against open `alert_events` (fire new / resolve gone).
 */
enum AlertCondition: string
{
    case DeviceDown = 'device_down';       // a device is unreachable
    case HighUtil = 'high_util';           // a link is at/over a utilisation threshold
    case LowThroughput = 'low_throughput'; // a link's throughput has dropped below a floor
    case InterfaceDown = 'interface_down'; // a port is operationally down while its device is up
    case UpgradeFailed = 'upgrade_failed'; // a firmware upgrade failed
    case NewDiscovery = 'new_discovery';   // a new device awaits review
    case BackupFailed = 'backup_failed';   // a device's last config backup failed
    case HighMetric = 'high_metric';       // a device metric (cpu/mem/temp) is at/over a threshold
    case ProbeDown = 'probe_down';         // a service probe (HTTP/TCP) is failing

    public function label(): string
    {
        return match ($this) {
            self::DeviceDown => 'Device down / recovered',
            self::HighUtil => 'Sustained high utilisation',
            self::LowThroughput => 'Low link throughput',
            self::InterfaceDown => 'Interface / port down',
            self::UpgradeFailed => 'Upgrade failed',
            self::NewDiscovery => 'New device discovered',
            self::BackupFailed => 'Config backup failed',
            self::HighMetric => 'High device metric (CPU / memory / temperature)',
            self::ProbeDown => 'Service probe down (HTTP / TCP)',
        };
    }
}
