import { useMemo } from 'react';
import type { Device } from '../../../types';
import { useDeviceMetricSamples } from '../api/getDeviceMetricSamples';
import { useDevicePingSamples } from '../api/getDevicePingSamples';

// One resource row: label, sparkline of its recent history, and the current value.
// Each metric auto-scales its own sparkline (no shared axis - %, C, ms all differ).
type Row = {
    key: string;
    label: string;
    color: string;
    current: number | null;
    series: number[];
    format: (v: number) => string;
};

function Sparkline({ values, color }: { values: number[]; color: string }) {
    const path = useMemo(() => {
        if (values.length < 2) return null;
        const min = Math.min(...values);
        const max = Math.max(...values);
        const span = max - min || 1;
        const w = 100;
        const h = 24;
        return values
            .map((v, i) => {
                const x = (i / (values.length - 1)) * w;
                const y = h - ((v - min) / span) * h;
                return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
            })
            .join(' ');
    }, [values]);

    if (path === null) {
        return <div className="h-6 flex-1 rounded bg-white/[0.03]" />;
    }

    return (
        <svg viewBox="0 0 100 24" preserveAspectRatio="none" className="h-6 flex-1">
            <path d={path} fill="none" stroke={color} strokeWidth={1.5} vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

const pct = (v: number) => `${v.toFixed(v < 10 ? 1 : 0)}%`;
const ms = (v: number) => `${v.toFixed(v < 10 ? 1 : 0)} ms`;

/**
 * Device health: latency + packet loss (every monitored device), plus CPU / memory /
 * temperature when the device reports them. Each row shows the current value and a recent
 * sparkline; rows with no data at all are dropped so a ping-only device shows just latency.
 */
export function DeviceResources({ device }: { device: Device }) {
    const metrics = useDeviceMetricSamples(device.id, 3600).data ?? [];
    const ping = useDevicePingSamples(device.id, 3600).data ?? [];

    const seriesOf = <T,>(rows: T[], field: keyof T): number[] =>
        rows.map((s) => s[field] as number | null).filter((v: number | null): v is number => v !== null);

    const rows: Row[] = [
        { key: 'latency', label: 'Latency', color: '#a78bfa', current: device.rtt_ms, series: seriesOf(ping, 'rtt_ms'), format: ms },
        { key: 'loss', label: 'Loss', color: '#f87171', current: device.loss_pct, series: seriesOf(ping, 'loss_pct'), format: pct },
        { key: 'cpu', label: 'CPU', color: '#34d399', current: device.cpu_pct, series: seriesOf(metrics, 'cpu_pct'), format: pct },
        { key: 'mem', label: 'Memory', color: '#38bdf8', current: device.mem_used_pct, series: seriesOf(metrics, 'mem_used_pct'), format: pct },
        { key: 'temp', label: 'Temp', color: '#fbbf24', current: device.temp_c, series: seriesOf(metrics, 'temp_c'), format: (v: number) => `${Math.round(v)}°C` },
    ].filter((r) => r.current !== null || r.series.length > 0);

    if (rows.length === 0) {
        return <div className="text-xs text-white/35">No health data yet - collected on the next sweep.</div>;
    }

    return (
        <div className="space-y-2">
            {rows.map((r) => (
                <div key={r.key} className="flex items-center gap-2.5">
                    <span className="w-14 shrink-0 text-[11px] font-medium text-white/50">{r.label}</span>
                    <Sparkline values={r.series} color={r.color} />
                    <span className="w-14 shrink-0 text-right text-xs tabular-nums text-white/80">
                        {r.current === null ? '-' : r.format(r.current)}
                    </span>
                </div>
            ))}
        </div>
    );
}
