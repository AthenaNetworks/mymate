<?php

namespace App\Services\Tools;

/**
 * A pure-PHP NetBIOS node-status query (NBNS, UDP 137) - the "nbtscan" of one host,
 * without the binary. It asks the host to list its NetBIOS names and returns the
 * workstation name plus the adapter MAC, which is often the friendliest identifier you
 * get for a Windows box or a NAS on a flat L2. Returns null when the host doesn't answer
 * (137 firewalled, not a NetBIOS host, or simply down).
 */
class NetbiosLookup
{
    /**
     * @return array{name: ?string, group: ?string, mac: ?string}|null
     */
    public static function query(string $ip, int $timeoutMs = 700): ?array
    {
        $sock = @stream_socket_client("udp://{$ip}:137", $errno, $errstr, $timeoutMs / 1000.0);
        if ($sock === false) {
            return null;
        }

        try {
            @fwrite($sock, self::nodeStatusRequest());

            $read = [$sock];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 0, $timeoutMs * 1000);
            if ($ready === false || $ready === 0) {
                return null; // no reply inside the window
            }

            $response = @fread($sock, 2048);
        } finally {
            fclose($sock);
        }

        if (! is_string($response) || strlen($response) < 57) {
            return null;
        }

        return self::parse($response);
    }

    /**
     * Node Status Request for the wildcard name "*": a 50-byte packet (12-byte header +
     * the encoded name + NBSTAT/IN question). The name is first-level encoded - every
     * byte becomes two nibble-plus-'A' characters, so the 16-byte "*" padded name is 32
     * bytes on the wire.
     */
    private static function nodeStatusRequest(): string
    {
        $header = pack('n6', 0x1337, 0x0000, 1, 0, 0, 0); // txn id, flags, QD=1, AN/NS/AR=0

        $name = '*'.str_repeat("\0", 15); // 16-byte NetBIOS name, "*" wildcard
        $encoded = '';
        foreach (str_split($name) as $ch) {
            $b = ord($ch);
            $encoded .= chr(0x41 + ($b >> 4)).chr(0x41 + ($b & 0x0F));
        }

        $question = chr(0x20).$encoded."\0"; // length 32, encoded name, root terminator
        $question .= pack('n2', 0x0021, 0x0001); // QTYPE=NBSTAT, QCLASS=IN

        return $header.$question;
    }

    /**
     * @return array{name: ?string, group: ?string, mac: ?string}|null
     */
    private static function parse(string $r): ?array
    {
        // Walk the echoed answer name (a run of length-prefixed labels ending in a 0 byte)
        // so we land on the RR fixed fields regardless of the name's exact length.
        $pos = 12;
        $len = strlen($r);
        while ($pos < $len && ($lab = ord($r[$pos])) !== 0) {
            $pos += 1 + $lab;
        }
        $pos += 1; // the zero terminator

        $pos += 2 + 2 + 4 + 2; // TYPE + CLASS + TTL + RDLENGTH - we trust RDATA's own count
        if ($pos >= $len) {
            return null;
        }

        $numNames = ord($r[$pos]);
        $pos += 1;

        $name = null;
        $group = null;
        for ($i = 0; $i < $numNames && $pos + 18 <= $len; $i++, $pos += 18) {
            $label = rtrim(substr($r, $pos, 15));
            $suffix = ord($r[$pos + 15]);
            $flags = unpack('n', substr($r, $pos + 16, 2))[1];
            $isGroup = (bool) ($flags & 0x8000);

            if ($isGroup) {
                $group ??= $label; // first group name = the workgroup/domain
            } elseif ($suffix === 0x00) {
                $name ??= $label; // first unique <00> = the workstation name
            }
        }

        // The 6-byte adapter MAC (unit id) follows the name list. All-zero means "not reported".
        $mac = null;
        if ($pos + 6 <= $len) {
            $raw = substr($r, $pos, 6);
            if ($raw !== "\0\0\0\0\0\0") {
                $mac = implode(':', array_map(fn ($b) => sprintf('%02x', ord($b)), str_split($raw)));
            }
        }

        if ($name === null && $group === null && $mac === null) {
            return null;
        }

        return ['name' => $name, 'group' => $group, 'mac' => $mac];
    }
}
