/**
 * Parse a backend history timestamp to epoch millis, treating a zone-less value as UTC.
 *
 * Postgres `date_bin` buckets and raw sample rows come back as "YYYY-MM-DD HH:MM:SS" with no
 * timezone - and the server stores history in UTC. JS parses a zone-less date-time as *local*,
 * which made the charts render the raw UTC clock value while the rest of the GUI shows local time
 * (GitHub #36). Tagging it UTC lets `toLocaleString` convert it to the viewer's zone like everywhere
 * else. A value that already carries a zone (Z or a +hh:mm / -hh:mm offset, e.g. an ISO API field)
 * is parsed as-is.
 */
export function parseUtc(s: string): number {
    const iso = s.includes('T') ? s : s.replace(' ', 'T');
    return /(Z|[+-]\d\d:?\d\d)$/.test(iso) ? Date.parse(iso) : Date.parse(iso + 'Z');
}
