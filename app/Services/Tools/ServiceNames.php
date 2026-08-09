<?php

namespace App\Services\Tools;

/**
 * A tiny well-known port -> service name map, plus the default "common ports" list the
 * port scan and (when enabled) the sweep probe. This is a labelling convenience, not
 * real service detection - a connect scan only proves something is listening, not what.
 */
class ServiceNames
{
    /** @var array<int, string> */
    private const NAMES = [
        20 => 'ftp-data', 21 => 'ftp', 22 => 'ssh', 23 => 'telnet', 25 => 'smtp',
        53 => 'dns', 67 => 'dhcp', 68 => 'dhcp', 69 => 'tftp', 80 => 'http',
        110 => 'pop3', 111 => 'rpcbind', 123 => 'ntp', 135 => 'msrpc', 137 => 'netbios-ns',
        138 => 'netbios-dgm', 139 => 'netbios-ssn', 143 => 'imap', 161 => 'snmp', 162 => 'snmp-trap',
        179 => 'bgp', 389 => 'ldap', 443 => 'https', 445 => 'microsoft-ds', 465 => 'smtps',
        500 => 'isakmp', 514 => 'syslog', 515 => 'printer', 520 => 'rip', 587 => 'submission',
        623 => 'ipmi', 636 => 'ldaps', 993 => 'imaps', 995 => 'pop3s', 1194 => 'openvpn',
        1433 => 'mssql', 1521 => 'oracle', 1723 => 'pptp', 1883 => 'mqtt', 2000 => 'cisco-sccp',
        2049 => 'nfs', 2082 => 'cpanel', 2083 => 'cpanel-ssl', 3128 => 'squid', 3260 => 'iscsi',
        3306 => 'mysql', 3389 => 'rdp', 4789 => 'vxlan', 5060 => 'sip', 5061 => 'sip-tls',
        5432 => 'postgres', 5900 => 'vnc', 5985 => 'winrm', 5986 => 'winrm-ssl', 6379 => 'redis',
        8000 => 'http-alt', 8006 => 'proxmox', 8080 => 'http-proxy', 8443 => 'https-alt', 8728 => 'mikrotik-api',
        8729 => 'mikrotik-api-ssl', 8291 => 'winbox', 9090 => 'http-alt', 9100 => 'jetdirect', 10000 => 'webmin',
        27017 => 'mongodb', 32400 => 'plex', 51820 => 'wireguard',
    ];

    /** The default set a port scan / sweep uses when the caller doesn't name ports. */
    public const COMMON_PORTS = [
        21, 22, 23, 25, 53, 80, 110, 111, 135, 139, 143, 161, 179, 389, 443, 445,
        465, 514, 587, 636, 993, 995, 1433, 1521, 1723, 2049, 3306, 3389, 5432, 5900,
        5985, 6379, 8006, 8080, 8291, 8443, 8728, 8729, 9100, 10000,
    ];

    public static function for(int $port): ?string
    {
        return self::NAMES[$port] ?? null;
    }
}
