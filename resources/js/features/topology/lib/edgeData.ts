import type { UtilEdgeData } from '../edges/UtilEdge';
import type { DeviceStatus, Link } from '../../../types';

// Static per-edge identity the live recolour pass needs (kept in edge.data). Each link
// carries its effective speed per direction: the link override, else the slower of the two
// end interfaces - what util% is computed against. Shared by the interactive MapCanvas and the
// read-only public wallboard (GitHub #15) so both colour links identically.
export type EdgeMeta = {
    aIf: number | null; // null = ping-only end (no interface, no throughput)
    bIf: number | null;
    aDev: number;
    bDev: number;
    effAb: number | null; // A->B effective speed (Mbps) - util_ab denominator
    effBa: number | null; // B->A effective speed (Mbps) - util_ba denominator
    aCost: number | null; // OSPF cost out of each end's interface (directional)
    bCost: number | null;
};

// Per-interface live signal: raw bps (the link util is derived from these) + per-port util%.
export type UtilMap = Record<number, { in: number | null; out: number | null; bin: number | null; bout: number | null }>;

export const metaOf = (l: Link): EdgeMeta => ({
    aIf: l.a_interface_id,
    bIf: l.b_interface_id,
    aDev: l.a_device_id,
    bDev: l.b_device_id,
    effAb: l.eff_ab_mbps,
    effBa: l.eff_ba_mbps,
    aCost: l.a_interface?.ospf_cost ?? null,
    bCost: l.b_interface?.ospf_cost ?? null,
});

export const linkUtil = (l: Link): UtilMap => {
    const m: UtilMap = {};
    // Skip ping-only ends (no interface -> nothing to key or fold).
    if (l.a_interface_id !== null && l.a_interface) {
        m[l.a_interface_id] = { in: l.a_interface.util_in, out: l.a_interface.util_out, bin: l.a_interface.bps_in, bout: l.a_interface.bps_out };
    }
    if (l.b_interface_id !== null && l.b_interface) {
        m[l.b_interface_id] = { in: l.b_interface.util_in, out: l.b_interface.util_out, bin: l.b_interface.bps_in, bout: l.b_interface.bps_out };
    }
    return m;
};

// Max of a list ignoring null/undefined; null when there's nothing to compare.
export const maxNum = (vals: Array<number | null | undefined>): number | null => {
    const nums = vals.filter((v): v is number => v != null);
    return nums.length ? Math.max(...nums) : null;
};

// The edge's colour/label inputs:
//  - util (colour): the busier of the two directions' utilisation = directional raw bps /
//    the link's effective speed for that direction; null when no direction has a known
//    speed, so the edge stays NEUTRAL (never ramped/green) per spec.
//  - mbps (label): the busiest directional throughput (shown always, even with no speed).
export function computeData(meta: EdgeMeta, util: UtilMap, statusById: Record<number, DeviceStatus>): UtilEdgeData & EdgeMeta {
    const a = meta.aIf !== null ? util[meta.aIf] : undefined;
    const b = meta.bIf !== null ? util[meta.bIf] : undefined;

    // Directional throughput (bits/sec): A->B = traffic leaving A (a.bout) ~ arriving at B
    // (b.bin); B->A = b.bout ~ a.bin. Max to be robust to one end lacking a sample.
    const abBps = maxNum([a?.bout, b?.bin]);
    const baBps = maxNum([b?.bout, a?.bin]);

    const utilAb = abBps != null && meta.effAb ? (abBps / (meta.effAb * 1_000_000)) * 100 : null;
    const utilBa = baBps != null && meta.effBa ? (baBps / (meta.effBa * 1_000_000)) * 100 : null;
    const max = maxNum([utilAb, utilBa]);

    const maxBps = maxNum([abBps, baBps]);
    const mbps = maxBps != null ? maxBps / 1_000_000 : null;

    const down = statusById[meta.aDev] === 'down' || statusById[meta.bDev] === 'down';

    return { ...meta, util: max, mbps, down };
}
