import type { PlaybackChunk } from '../api/playback';
import type { Device, Link } from '../../../types';

type Series = Array<number | null>;

/**
 * Everything loaded so far for one playback window (GitHub #22), with the chunks folded into
 * full-length arrays so a frame is just an index. `loaded[i]` says whether frame i's series have
 * arrived yet; the axis, down frames and outages come complete with the first chunk.
 */
export interface PlaybackStore {
    frames: number[]; // unix seconds, frame starts
    step: number;
    tier: PlaybackChunk['tier'];
    deviceIds: Set<number>;
    interfaces: Map<number, { in: Series; out: Series }>;
    devices: Map<number, { rtt: Series; loss: Series }>;
    downAt: Array<Set<number>>; // per frame, the devices down in it
    outages: PlaybackChunk['outages'];
    loaded: boolean[];
}

export function createStore(first: PlaybackChunk): PlaybackStore {
    const n = first.frames.length;
    const downAt = Array.from({ length: n }, () => new Set<number>());
    for (const [id, idx] of Object.entries(first.down)) {
        for (const i of idx) downAt[i]?.add(Number(id));
    }
    const store: PlaybackStore = {
        frames: first.frames,
        step: first.step,
        tier: first.tier,
        deviceIds: new Set(first.device_ids),
        interfaces: new Map(),
        devices: new Map(),
        downAt,
        outages: first.outages,
        loaded: new Array<boolean>(n).fill(false),
    };
    mergeChunk(store, first);
    return store;
}

/** Same window? A chunk only belongs to a store if its axis is the one the store was built on. */
export function sameAxis(store: PlaybackStore, chunk: PlaybackChunk): boolean {
    return chunk.step === store.step && chunk.frames.length === store.frames.length && chunk.frames[0] === store.frames[0];
}

/** Fold one chunk's series into the store (in place). */
export function mergeChunk(store: PlaybackStore, chunk: PlaybackChunk): void {
    const n = store.frames.length;
    const blank = (): Series => new Array<number | null>(n).fill(null);
    for (const [id, s] of Object.entries(chunk.interfaces)) {
        let row = store.interfaces.get(Number(id));
        if (!row) store.interfaces.set(Number(id), (row = { in: blank(), out: blank() }));
        for (let j = 0; j < chunk.limit; j++) {
            row.in[chunk.offset + j] = s.bps_in[j];
            row.out[chunk.offset + j] = s.bps_out[j];
        }
    }
    for (const [id, s] of Object.entries(chunk.devices)) {
        let row = store.devices.get(Number(id));
        if (!row) store.devices.set(Number(id), (row = { rtt: blank(), loss: blank() }));
        for (let j = 0; j < chunk.limit; j++) {
            row.rtt[chunk.offset + j] = s.rtt_ms[j];
            row.loss[chunk.offset + j] = s.loss_pct[j];
        }
    }
    for (let j = 0; j < chunk.limit; j++) store.loaded[chunk.offset + j] = true;
}

/** First frame at or after `from` that hasn't loaded yet, wrapping round; null when all have. */
export function nextUnloaded(store: PlaybackStore, from: number): number | null {
    const n = store.loaded.length;
    for (let k = 0; k < n; k++) {
        const i = (from + k) % n;
        if (!store.loaded[i]) return i;
    }
    return null;
}

/** Index of the frame holding unix time `t` (clamped to the window). */
export function frameAt(store: PlaybackStore, t: number): number {
    const i = Math.floor((t - store.frames[0]) / store.step);
    return Math.max(0, Math.min(store.frames.length - 1, i));
}

/**
 * The devices as they were in frame i: status from the outages (down if it was down at any point
 * in the frame), rtt/loss from the frame's ping average. cpu/mem/temp aren't part of playback, so
 * they're blanked rather than left showing today's values on a historical frame.
 */
export function frameDevices(devices: Device[], store: PlaybackStore, i: number): Device[] {
    const down = store.downAt[i];
    return devices.map((d) => {
        if (!store.deviceIds.has(d.id)) return d;
        const ping = store.devices.get(d.id);
        return {
            ...d,
            status: down?.has(d.id) ? 'down' : d.status === 'unknown' ? 'unknown' : 'up',
            rtt_ms: ping?.rtt[i] ?? null,
            loss_pct: ping?.loss[i] ?? null,
            cpu_pct: null,
            mem_used_pct: null,
            temp_c: null,
        };
    });
}

/**
 * The links as they were in frame i: each end's bps swapped for the frame's, so computeData
 * colours and labels them exactly the way live mode would have. Per-port util isn't used for the
 * link colour, so it's just cleared.
 */
export function frameLinks(links: Link[], store: PlaybackStore, i: number): Link[] {
    const end = (iface: Link['a_interface']): Link['a_interface'] => {
        if (!iface) return iface;
        const s = store.interfaces.get(iface.id);
        return { ...iface, bps_in: s?.in[i] ?? null, bps_out: s?.out[i] ?? null, util_in: null, util_out: null };
    };
    return links.map((l) => ({ ...l, a_interface: end(l.a_interface), b_interface: end(l.b_interface) }));
}
