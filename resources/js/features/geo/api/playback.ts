import { apiClient } from '../../../lib/apiClient';

/**
 * One response from GET /maps/{map}/playback (GitHub #22). Every series is an array aligned to
 * `frames` (unix seconds, one per frame start), null where there was no sample. The axis, `down`
 * and `outages` always cover the whole window; the interface/device series only hold frames
 * [offset, offset + limit), so a big map pages through a long window chunk by chunk.
 */
export interface PlaybackChunk {
    mode: 'range' | 'at';
    tier: 'raw' | '5m' | '1h';
    step: number; // frame width, seconds
    from: string;
    to: string;
    at?: string;
    frames: number[];
    offset: number;
    limit: number;
    device_ids: number[];
    interfaces: Record<string, { bps_in: Array<number | null>; bps_out: Array<number | null> }>;
    devices: Record<string, { rtt_ms: Array<number | null>; loss_pct: Array<number | null> }>;
    down: Record<string, number[]>; // device id -> frame indexes it was down in
    outages: Array<[number, number, number | null]>; // [device_id, start, end|null], unix seconds
}

export interface PlaybackQuery {
    from: string; // ISO
    to: string;
    points?: number;
    offset?: number;
    limit?: number;
}

export async function fetchPlayback(mapId: number, q: PlaybackQuery, signal?: AbortSignal): Promise<PlaybackChunk> {
    const { data } = await apiClient.get<{ data: PlaybackChunk }>(`/maps/${mapId}/playback`, { params: q, signal });
    return data.data;
}
