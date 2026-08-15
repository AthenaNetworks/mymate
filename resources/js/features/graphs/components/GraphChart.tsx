import { useMemo, useRef, useState } from 'react';
import { formatRate } from '../../../lib/formatRate';
import { parseUtc } from '../../../lib/parseUtc';
import type { GraphData, GraphSeriesFormat } from '../../../types';

// Same SVG drawing space + conventions as the device-modal interface chart, extended to many
// series from any source (interface throughput/util, custom OID, ping latency, probe latency).
// Colour carries series identity (validated categorical palette); interface out is dashed; the
// combined total is a bold pale line.
const W = 760;
const H = 260;
const PAD = { t: 14, r: 16, b: 26, l: 60 };

const PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
const TOTAL = '#e8e6dc';

const fmtTime = (t: number) => new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const fmtDate = (t: number) => new Date(t).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

function fmtValue(v: number, format: GraphSeriesFormat, unit: string | null): string {
    if (format === 'rate') return formatRate(v);
    if (format === 'util') return `${v.toFixed(v < 10 ? 1 : 0)}%`;
    if (format === 'ms') return `${v < 10 ? v.toFixed(1) : Math.round(v)} ms`;
    return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ` ${unit}` : ''}`;
}

type Line = { key: string; label: string; color: string; dashed: boolean; emphasized: boolean; format: GraphSeriesFormat; unit: string | null; values: (number | null)[] };

export function GraphChart({ data }: { data: GraphData }) {
    const [hover, setHover] = useState<number | null>(null);
    const wrap = useRef<HTMLDivElement>(null);

    const times = useMemo(() => data.buckets.map((b) => parseUtc(b)), [data.buckets]);

    // The left axis is driven by the traffic/main series (rate/util/value). Latency (ms) series
    // get their own right-hand scale, so overlaying a 2 ms line on a 25 Mbps one keeps both
    // readable - and the left axis stays in kbps/Mbps instead of dropping to raw bits to fit both.
    const leftFmt = useMemo<{ format: GraphSeriesFormat; unit: string | null }>(() => {
        const main = data.series.filter((s) => s.format !== 'ms');
        const src = main.length ? main : data.series; // an all-latency graph just uses ms on the left
        const fmts = new Set(src.map((s) => s.format));
        const format = fmts.size === 1 ? [...fmts][0] : 'value';
        return { format, unit: src.find((s) => s.format === format)?.unit ?? null };
    }, [data.series]);
    const fmtY = (v: number) => fmtValue(v, leftFmt.format, leftFmt.unit);

    const lines = useMemo<Line[]>(() => {
        const colorOf = new Map<string, string>();
        for (const s of data.series) if (!colorOf.has(s.group)) colorOf.set(s.group, PALETTE[colorOf.size % PALETTE.length]);
        const out: Line[] = data.series.map((s, i) => ({
            key: `${s.group}:${i}`, label: s.label, color: colorOf.get(s.group) ?? PALETTE[0],
            dashed: s.dashed, emphasized: false, format: s.format, unit: s.unit, values: s.values,
        }));
        if (data.total) out.push({ key: 'total', label: 'Total', color: TOTAL, dashed: false, emphasized: true, format: leftFmt.format, unit: leftFmt.unit, values: data.total });
        return out;
    }, [data, leftFmt]);

    // Latency series get their own scale, but only when there's a traffic series to sit alongside.
    const msLines = useMemo(() => lines.filter((l) => l.format === 'ms'), [lines]);
    const mainLines = useMemo(() => lines.filter((l) => l.format !== 'ms'), [lines]);
    const dualMs = msLines.length > 0 && mainLines.length > 0;

    const tMin = times.length ? Math.min(...times) : 0;
    const tMax = times.length ? Math.max(...times) : 1;
    const tSpan = Math.max(1, tMax - tMin);
    const peak = Math.max(1, ...(mainLines.length ? mainLines : lines).flatMap((l) => l.values.map((v) => v ?? 0)));
    const yMax = peak * 1.15;
    const msPeak = Math.max(1, ...msLines.flatMap((l) => l.values.map((v) => v ?? 0)));
    const msMax = msPeak * 1.15;

    const padR = dualMs ? 46 : PAD.r; // room for the right-hand ms axis labels
    const x = (t: number) => PAD.l + ((t - tMin) / tSpan) * (W - PAD.l - padR);
    const y = (v: number) => PAD.t + (1 - v / yMax) * (H - PAD.t - PAD.b); // left/main scale
    const yMs = (v: number) => PAD.t + (1 - v / msMax) * (H - PAD.t - PAD.b); // right/latency scale
    const scaleFor = (l: Line) => (dualMs && l.format === 'ms' ? yMs : y);

    const path = (values: (number | null)[], sc: (v: number) => number) => {
        let d = '';
        let pen = false;
        values.forEach((v, i) => {
            if (v == null || times[i] === undefined) { pen = false; return; }
            d += `${pen ? 'L' : 'M'}${x(times[i]).toFixed(1)} ${sc(v).toFixed(1)} `;
            pen = true;
        });
        return d.trim();
    };

    // Fractions 0..1; both axes share the plot height (0 at bottom), left labelled in the traffic
    // unit, right in ms when latency is overlaid.
    const ticks = [0, 0.5, 1];
    const noData = lines.length === 0 || lines.every((l) => l.values.every((v) => v == null));

    function onMove(e: React.MouseEvent) {
        const rect = wrap.current?.getBoundingClientRect();
        if (!rect || times.length === 0) return;
        const px = ((e.clientX - rect.left) / rect.width) * W;
        const frac = Math.min(1, Math.max(0, (px - PAD.l) / (W - PAD.l - PAD.r)));
        setHover(Math.round(frac * (times.length - 1)));
    }

    const hi = hover != null && hover >= 0 && hover < times.length ? hover : null;

    return (
        <div className="space-y-2">
            <div ref={wrap} className="relative" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
                <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="Custom graph">
                    {ticks.map((f, i) => {
                        const yp = PAD.t + (1 - f) * (H - PAD.t - PAD.b);
                        return (
                            <g key={i}>
                                <line x1={PAD.l} x2={W - padR} y1={yp} y2={yp} stroke="rgba(255,255,255,0.08)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                <text x={PAD.l - 6} y={yp + 3} textAnchor="end" className="fill-white/35" fontSize={10}>{fmtY(yMax * f)}</text>
                                {dualMs && (
                                    <text x={W - padR + 6} y={yp + 3} textAnchor="start" className="fill-white/30" fontSize={10}>{fmtValue(msMax * f, 'ms', null)}</text>
                                )}
                            </g>
                        );
                    })}

                    {!noData && lines.map((l) => (
                        <path key={l.key} d={path(l.values, scaleFor(l))} fill="none" stroke={l.color}
                            strokeWidth={l.emphasized ? 2.4 : 1.8} strokeDasharray={l.dashed ? '5 3' : undefined}
                            strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" opacity={l.emphasized ? 0.95 : 0.9} />
                    ))}

                    {hi != null && !noData && (
                        <g>
                            <line x1={x(times[hi])} x2={x(times[hi])} y1={PAD.t} y2={H - PAD.b} stroke="rgba(255,255,255,0.25)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                            {lines.map((l) => (l.values[hi] != null ? (
                                <circle key={l.key} cx={x(times[hi])} cy={scaleFor(l)(l.values[hi] as number)} r={2.6} fill={l.color} stroke="var(--color-surface)" strokeWidth={1} />
                            ) : null))}
                        </g>
                    )}

                    <text x={PAD.l} y={H - 6} textAnchor="start" className="fill-white/35" fontSize={10}>{times.length ? fmtTime(tMin) : ''}</text>
                    <text x={W - padR} y={H - 6} textAnchor="end" className="fill-white/35" fontSize={10}>{times.length ? fmtTime(tMax) : ''}</text>
                </svg>

                {noData && (
                    <div className="pointer-events-none absolute inset-0 grid place-items-center text-xs text-white/35">No data in this range yet.</div>
                )}

                {hi != null && !noData && (
                    <div className="pointer-events-none absolute top-2 rounded-lg bg-surface/95 px-2.5 py-1.5 text-[11px] shadow-[0_8px_24px_-8px_rgba(0,0,0,0.8)] ring-1 ring-white/10"
                        style={{ left: `${Math.min(72, (x(times[hi]) / W) * 100)}%` }}>
                        <div className="mb-1 font-mono text-[10px] text-white/45">{fmtDate(times[hi])}</div>
                        {lines.map((l) => (
                            <div key={l.key} className="flex items-center gap-1.5">
                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: l.color }} />
                                <span className="min-w-0 flex-1 truncate text-white/60">{l.label}</span>
                                <span className="ml-2 font-mono tabular-nums text-white/85">{l.values[hi] != null ? fmtValue(l.values[hi] as number, l.format, l.unit) : '-'}</span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {lines.length >= 2 && (
                <div className="flex flex-wrap gap-x-3 gap-y-1 px-1 text-[11px]">
                    {lines.map((l) => (
                        <span key={l.key} className="flex items-center gap-1.5 text-white/60">
                            <span className="inline-block h-0.5 w-4 rounded" style={{ backgroundColor: l.color, opacity: l.dashed ? 0.6 : 1 }} />
                            {l.label}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
