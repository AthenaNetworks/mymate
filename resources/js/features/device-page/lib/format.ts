import { formatRate } from '../../../lib/formatRate';

// Value and time formatting for the device page graphs, keyed off the unit the history catalog
// reports for a metric, so a family added later formats sensibly without a code change here.

const DEG = '°';

export function fmtBytes(b: number | null): string {
    if (b === null || !Number.isFinite(b)) return '-';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let v = Math.abs(b);
    let i = 0;
    while (v >= 1000 && i < units.length - 1) {
        v /= 1000;
        i++;
    }
    return `${b < 0 ? '-' : ''}${v.toFixed(v >= 100 || i === 0 ? 0 : v >= 10 ? 1 : 2)} ${units[i]}`;
}

export function fmtDuration(s: number): string {
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function num(v: number): string {
    const a = Math.abs(v);
    if (a >= 1e9) return `${(v / 1e9).toFixed(2)}G`;
    if (a >= 1e6) return `${(v / 1e6).toFixed(2)}M`;
    if (a >= 1e4) return `${(v / 1e3).toFixed(1)}k`;
    return v.toLocaleString(undefined, { maximumFractionDigits: a < 10 ? 2 : a < 100 ? 1 : 0 });
}

/** A value in its unit, compact enough for an axis tick or a legend cell. */
export function fmtValue(v: number | null | undefined, unit: string | null): string {
    if (v === null || v === undefined || !Number.isFinite(v)) return '-';
    switch (unit) {
        case 'bps':
            return formatRate(Math.abs(v));
        case '%':
            return `${v.toFixed(Math.abs(v) < 10 ? 1 : 0)}%`;
        case 'ms':
            return `${Math.abs(v) < 10 ? v.toFixed(2) : Math.abs(v) < 100 ? v.toFixed(1) : Math.round(v)} ms`;
        case 'C':
            return `${v.toFixed(1)}${DEG}C`;
        case 'dBm':
        case 'dB':
            return `${v.toFixed(1)} ${unit}`;
        case 's':
            return fmtDuration(v);
        case 'B':
            return fmtBytes(v);
        case 'pps':
            return `${num(v)} pps`;
        case null:
        case '':
            return num(v);
        default:
            return `${num(v)} ${unit}`;
    }
}

/** Axis label for a time tick, detail picked by how wide the window is. */
export function fmtTick(t: number, spanSec: number): string {
    const d = new Date(t);
    if (spanSec <= 2 * 86400) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (spanSec <= 10 * 86400) return d.toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    if (spanSec <= 400 * 86400) return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    return d.toLocaleDateString([], { month: 'short', year: 'numeric' });
}

export function fmtStamp(t: number): string {
    return new Date(t).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Rounded-up axis maximum: 1, 2, 2.5 or 5 times a power of ten (bits use the same steps). */
export function niceMax(v: number): number {
    if (!(v > 0)) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    for (const m of [1, 2, 2.5, 5, 10]) {
        if (v <= m * p) return m * p;
    }
    return 10 * p;
}

export const GROUP_TITLES: Record<string, string> = {
    traffic: 'Traffic',
    latency: 'Latency, loss and jitter',
    cpu: 'CPU',
    memory: 'Memory',
    storage: 'Storage',
    temperature: 'Temperature',
    sensors: 'Sensors',
    wireless: 'Wireless',
    optical: 'Optical',
    probes: 'Probes',
    uptime: 'Uptime',
    port_errors: 'Port errors, discards and packets',
    port_status: 'Port status',
    routing: 'Routing',
    health: 'Health',
    other: 'Other',
};

/** Section order on the Graphs tab; unknown groups go last, alphabetically. */
export const GROUP_ORDER = [
    'traffic', 'latency', 'cpu', 'memory', 'storage', 'temperature', 'sensors', 'wireless',
    'optical', 'probes', 'uptime', 'port_errors', 'port_status', 'routing', 'health', 'other',
];

export function groupTitle(g: string): string {
    return GROUP_TITLES[g] ?? g.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}
