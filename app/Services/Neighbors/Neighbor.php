<?php

namespace App\Services\Neighbors;

/**
 * One neighbour a device reported on one of its ports, before it's matched to My Mate's devices.
 * Ports are lists of candidate names because devices disagree about what they call one: the port
 * id, its description, an alias, sometimes a bare number.
 */
final class Neighbor
{
    /**
     * @param  list<string>  $localPorts  names for our own port, best first
     * @param  ?int  $localIfIndex  our port's ifIndex when the protocol gives it for certain (CDP)
     * @param  ?int  $localPortNum  LLDP's local port number - only sometimes the ifIndex (#85)
     * @param  list<string>  $remotePorts  names for the far port, best first
     */
    public function __construct(
        public readonly string $protocol,
        public readonly array $localPorts = [],
        public readonly ?int $localIfIndex = null,
        public readonly ?int $localPortNum = null,
        public readonly ?string $remoteChassis = null,
        public readonly ?string $remoteName = null,
        public readonly array $remotePorts = [],
        public readonly ?string $remoteIp = null,
    ) {}
}
