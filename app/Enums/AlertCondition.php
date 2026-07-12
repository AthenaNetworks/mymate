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
    case UpgradeFailed = 'upgrade_failed'; // a firmware upgrade failed
    case NewDiscovery = 'new_discovery';   // a new device awaits review

    public function label(): string
    {
        return match ($this) {
            self::DeviceDown => 'Device down / recovered',
            self::HighUtil => 'Sustained high utilisation',
            self::UpgradeFailed => 'Upgrade failed',
            self::NewDiscovery => 'New device discovered',
        };
    }
}
