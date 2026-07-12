// Single source for bit-rate formatting: auto-scale a bits/sec value
// to b / kbps / Mbps / Gbps / Tbps so it\'s legible at any magnitude. Used by the map
// edges, the device inspector, and the throughput chart - was duplicated before.

// Bits (the base unit) keep an explicit "bps" suffix so a small rate reads as "25bps",
// not a bare "25"; k/M/G/T stay tight in compact mode.
const COMPACT = ['bps', 'k', 'M', 'G', 'T'] as const;
const FULL = ['bps', 'kbps', 'Mbps', 'Gbps', 'Tbps'] as const;

function trim(n: string): string {
    return n.includes('.') ? n.replace(/\.?0+$/, '') : n;
}

/**
 * Format a bit-rate (bits/sec) with an auto-scaled unit.
 * `compact` -> tight edge-label style ("6.1G", "730M", "12k"); otherwise a full
 * label ("6.1 Gbps"). Null -> '' (compact) / '-' (full);<=0 -> '0' / '0 bps'.
 */
export function formatRate(bps: number | null, opts: { compact?: boolean } = {}): string {
    if (bps === null || !Number.isFinite(bps)) return opts.compact ? '' : '-';
    if (bps <= 0) return opts.compact ? '0' : '0 bps';

    const i = Math.min(Math.floor(Math.log10(Math.abs(bps)) / 3), COMPACT.length - 1);
    const value = bps / 1000 ** i;
    const digits = value >= 100 ? 0 : value >= 10 ? 1 : 2; // ~3 significant figures
    const num = trim(value.toFixed(digits));

    return opts.compact ? `${num}${COMPACT[i]}` : `${num} ${FULL[i]}`;
}

/** Convenience for callers holding Mbps (link capacity / load) rather than raw bps. */
export function formatMbps(mbps: number | null, opts: { compact?: boolean } = {}): string {
    return formatRate(mbps === null ? null : mbps * 1e6, opts);
}
