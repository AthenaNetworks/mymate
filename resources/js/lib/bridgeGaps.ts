/**
 * Fill the short null runs in a bucketed series by joining its neighbours with a straight line, for
 * drawing only. A series sampled less often than the bucket width (latency once a minute, a slow
 * SNMP counter) comes back with a null in most buckets on a short range, and a line that breaks on
 * every null draws nothing at all (GH #59). A run no longer than about the series' own usual spacing
 * is a sampling gap and gets bridged; anything longer is a real outage and stays a gap.
 */
export function bridgeGaps(values: (number | null)[]): (number | null)[] {
    const seen: number[] = [];
    values.forEach((v, i) => { if (v != null) seen.push(i); });
    if (seen.length < 2) return values;

    const gaps = seen.slice(1).map((idx, k) => idx - seen[k]).sort((a, b) => a - b);
    const usual = gaps[Math.floor(gaps.length / 2)];
    const maxGap = usual * 1.5; // dense data (usual 1) bridges nothing

    const out = values.slice();
    for (let k = 1; k < seen.length; k++) {
        const a = seen[k - 1];
        const b = seen[k];
        if (b - a < 2 || b - a > maxGap) continue;
        const va = values[a] as number;
        const vb = values[b] as number;
        for (let i = a + 1; i < b; i++) out[i] = va + ((vb - va) * (i - a)) / (b - a);
    }
    return out;
}
