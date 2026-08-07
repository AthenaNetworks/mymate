<?php

namespace App\Enums;

/**
 * How a service probe reaches its target (GitHub #19). HTTP(S) makes a request and checks the
 * status/body; TCP just opens a socket to a port. Deliberately a small, safe set - no arbitrary
 * OS command execution (that would be a remote-code-execution foothold on the monitoring host).
 */
enum ProbeKind: string
{
    case Http = 'http';
    case Tcp = 'tcp';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S) request',
            self::Tcp => 'TCP port',
        };
    }
}
