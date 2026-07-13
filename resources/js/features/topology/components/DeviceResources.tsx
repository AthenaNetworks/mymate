import { useMemo } from 'react';
import type { Device, DeviceMetricSample } from '../../../types';
import { useDeviceMetricSamples } from '../api/getDeviceMetricSamples';

// One resource row: label, sparkline of its recent history, and the current value.
// Each metric auto-scales its own sparkline (cpu/mem are %, temp is C - no shared axis).
type Row = {
    key: 'cpu' | 'mem' | 'temp';
    label: string;
    color: string;
    current: number | null;
    field: keyof DeviceMetricSample;
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

/**
 * Device resource metrics (cpu/mem/temp): current value + a recent-history sparkline per
 * metric. Renders nothing for a device that reports no metrics at all (ping-only, or gear
 * that doesn't answer the OIDs) so the inspector stays uncluttered.
 */
export function DeviceResources({ device }: { device: Device }) {
    const samples = useDeviceMetricSamples(device.id, 3600);
    const data = samples.data ?? [];

    const rows: Row[] = [
        { key: 'cpu', label: 'CPU', color: '#34d399', current: device.cpu_pct, field: 'cpu_pct', format: (v) => `${v.toFixed(v < 10 ? 1 : 0)}%` },
        { key: 'mem', label: 'Memory', color: '#38bdf8', current: device.mem_used_pct, field: 'mem_used_pct', format: (v) => `${v.toFixed(v < 10 ? 1 : 0)}%` },
        { key: 'temp', label: 'Temp', color: '#fbbf24', current: device.temp_c, field: 'temp_c', format: (v) => `${Math.round(v)}°C` },
    ];

    return (
        <div className="space-y-2">
            {rows.map((r) => {
                const series = data.map((s) => s[r.field] as number | null).filter((v): v is number => v !== null);
                return (
                    <div key={r.key} className="flex items-center gap-2.5">
                        <span className="w-14 shrink-0 text-[11px] font-medium text-white/50">{r.label}</span>
                        <Sparkline values={series} color={r.color} />
                        <span className="w-12 shrink-0 text-right text-xs tabular-nums text-white/80">
                            {r.current === null ? '-' : r.format(r.current)}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
