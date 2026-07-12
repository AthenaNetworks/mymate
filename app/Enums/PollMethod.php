<?php

namespace App\Enums;

enum PollMethod: string
{
    case Snmp = 'snmp';
    case RouterOs = 'routeros';

    /**
     * Ping-only: the device is pinged for up/down like any other,
     * but has **no throughput source** - the correct model for SNMP-less leaf devices
     * (cameras, PCs, unmanaged switches). It is deliberately excluded from the
     * throughput + interface-discovery loops (see LoopCommand's `[Snmp, RouterOs]`
     * selection), so it never generates a `poll: device poll failed` warning.
     */
    case None = 'none';

    /** Whether this method has a throughput driver (i.e. gets throughput-polled + discovered). */
    public function pollsThroughput(): bool
    {
        return $this !== self::None;
    }

    /** The methods that carry throughput - the canonical set the poll/discover loops select. */
    public static function throughputMethods(): array
    {
        return [self::Snmp, self::RouterOs];
    }
}
