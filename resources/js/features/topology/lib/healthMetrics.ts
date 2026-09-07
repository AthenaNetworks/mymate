import type { Device, DeviceMetricSample, DevicePingSample } from '../../../types';

/**
 * The device health metrics, shared by the inspector's Health sparklines (DeviceResources) and
 * the expanded HealthChartModal so the two never disagree on names, colours or formatting.
 * Each metric reads its history from one of two sample streams (ping or resource metrics) and
 * its live value off the Device row.
 */
export type HealthSource = 'ping' | 'metric';

export interface HealthMetric {
    key: string;
    label: string;
    color: string;
    source: HealthSource;
    field: keyof DevicePingSample | keyof DeviceMetricSample;
    current: (d: Device) => number | null;
    format: (v: number) => string;
    /** Chart y-axis starts at 0 (%, ms, counts) rather than min..max with padding (dBm, dB, °C). */
    zeroBased: boolean;
}

export const pct = (v: number) => `${v.toFixed(v < 10 ? 1 : 0)}%`;
export const ms = (v: number) => `${v.toFixed(v < 10 ? 1 : 0)} ms`;

export const HEALTH_METRICS: HealthMetric[] = [
    { key: 'latency', label: 'Latency', color: '#a78bfa', source: 'ping', field: 'rtt_ms', current: (d) => d.rtt_ms, format: ms, zeroBased: true },
    { key: 'loss', label: 'Loss', color: '#f87171', source: 'ping', field: 'loss_pct', current: (d) => d.loss_pct, format: pct, zeroBased: true },
    // Jitter is recorded with every ping sample but has no live column on the device row, so it
    // only appears once there is history to show.
    { key: 'jitter', label: 'Jitter', color: '#fb923c', source: 'ping', field: 'jitter_ms', current: () => null, format: ms, zeroBased: true },
    { key: 'cpu', label: 'CPU', color: '#34d399', source: 'metric', field: 'cpu_pct', current: (d) => d.cpu_pct, format: pct, zeroBased: true },
    { key: 'mem', label: 'Memory', color: '#38bdf8', source: 'metric', field: 'mem_used_pct', current: (d) => d.mem_used_pct, format: pct, zeroBased: true },
    { key: 'temp', label: 'Temp', color: '#fbbf24', source: 'metric', field: 'temp_c', current: (d) => d.temp_c, format: (v) => `${Math.round(v)}°C`, zeroBased: false },
    { key: 'signal', label: 'Signal', color: '#f472b6', source: 'metric', field: 'signal_dbm', current: (d) => d.signal_dbm, format: (v) => `${Math.round(v)} dBm`, zeroBased: false },
    { key: 'snr', label: 'SNR', color: '#2dd4bf', source: 'metric', field: 'snr_db', current: (d) => d.snr_db, format: (v) => `${Math.round(v)} dB`, zeroBased: false },
    { key: 'ccq', label: 'CCQ', color: '#22d3ee', source: 'metric', field: 'ccq_pct', current: (d) => d.ccq_pct, format: pct, zeroBased: true },
    { key: 'clients', label: 'Clients', color: '#c084fc', source: 'metric', field: 'wireless_clients', current: (d) => d.wireless_clients, format: (v) => `${Math.round(v)}`, zeroBased: true },
    { key: 'ospf', label: 'OSPF neighbours', color: '#818cf8', source: 'metric', field: 'ospf_neighbors', current: (d) => d.ospf_neighbors, format: (v) => `${Math.round(v)} full`, zeroBased: true },
];

type SampleRow = DevicePingSample | DeviceMetricSample;

function rowsFor(m: HealthMetric, ping: DevicePingSample[], metrics: DeviceMetricSample[]): SampleRow[] {
    return m.source === 'ping' ? ping : metrics;
}

function valueOf(row: SampleRow, field: HealthMetric['field']): number | null {
    const v = (row as unknown as Record<string, unknown>)[field as string];
    return typeof v === 'number' ? v : null;
}

/** Non-null values of the metric in sample order - what the index-based sparkline draws. */
export function healthSeries(m: HealthMetric, ping: DevicePingSample[], metrics: DeviceMetricSample[]): number[] {
    return rowsFor(m, ping, metrics)
        .map((r) => valueOf(r, m.field))
        .filter((v): v is number => v !== null);
}

/** Timestamped points of the metric (epoch ms, value) - what a real time-axis chart draws. */
export function healthPoints(
    m: HealthMetric,
    ping: DevicePingSample[],
    metrics: DeviceMetricSample[],
    parseTs: (s: string) => number,
): { t: number; v: number }[] {
    return rowsFor(m, ping, metrics)
        .map((r) => ({ t: parseTs(r.ts), v: valueOf(r, m.field) }))
        .filter((p): p is { t: number; v: number } => p.v !== null && !Number.isNaN(p.t));
}
