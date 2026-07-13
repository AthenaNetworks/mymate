<?php

namespace App\Support;

use App\Enums\PollMethod;
use App\Models\Device;

/**
 * The set of Rusted backup drivers My Mate knows about, plus a best-guess mapping from a
 * device's vendor/poll-method to one of them. Rusted picks the per-
 * platform command set (disable paging, dump + normalise config) by driver name; the
 * operator can always override the suggestion in the inspector.
 *
 * Driver names mirror Rusted's own (docs/drivers.md): the officially-supported trio plus
 * the bundled extras. Kept in one place so the Form Request `Rule::in(...)` and the driver
 * suggestion never drift apart.
 */
class RustedDrivers
{
    /** Every driver Rusted ships (github.com/JoshFinlayAU/rusted -> docs/drivers.md). */
    public const ALL = [
        'mikrotik_routeros',
        'cisco_nxos',
        'juniper_junos',
        'cisco_ios',
        'cisco_asa',
        'arista_eos',
        'fortinet',
        'vyos',
        'generic',
    ];

    /**
     * Suggest a driver for a device from what we already know about it. RouterOS is the
     * common case here (the whole fleet is MikroTik) - a RouterOS poll method or a
     * "mikrotik"/"routeros" vendor string both map to `mikrotik_routeros`. Falls back to a
     * few vendor-keyword matches, else null (unknown -> the operator must pick one before
     * backups can run, rather than us guessing `generic` and capturing garbage).
     */
    public static function suggestFor(Device $device): ?string
    {
        if ($device->poll_method === PollMethod::RouterOs) {
            return 'mikrotik_routeros';
        }

        $vendor = strtolower((string) $device->vendor);

        return match (true) {
            $vendor === '' => null,
            str_contains($vendor, 'mikrotik'), str_contains($vendor, 'routeros') => 'mikrotik_routeros',
            str_contains($vendor, 'juniper') => 'juniper_junos',
            str_contains($vendor, 'arista') => 'arista_eos',
            str_contains($vendor, 'fortinet'), str_contains($vendor, 'fortigate') => 'fortinet',
            str_contains($vendor, 'vyos') => 'vyos',
            str_contains($vendor, 'nexus'), str_contains($vendor, 'nx-os') => 'cisco_nxos',
            str_contains($vendor, 'cisco') => 'cisco_ios',
            default => null,
        };
    }
}
