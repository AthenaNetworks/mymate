import { useMemo, useRef, useState } from 'react';
import { formatRate } from '../../../lib/formatRate';
import { parseUtc } from '../../../lib/parseUtc';
import type { GraphColorMode, GraphData, GraphSeriesFormat } from '../../../types';

// The resolved look the chart draws with: fill/stacked and the colour mode (house default or
// per-graph) plus the series palette (house default; wraps when a graph has more series than
// colours). Missing fields fall back to the built-in defaults.
type ChartStyle = { fill: boolean; stacked: boolean; color_mode?: GraphColorMode; palette?: string[] | null };

// Same SVG drawing space + conventions as the device-modal interface chart, extended to many
// series from any source (interface throughput/util, custom OID, ping latency, probe latency).
// Colour carries series identity (validated categorical palette, or a per-series override);
// interface out is dashed; the combined total is a bold neutral line. Axes/grid/total read from
// CSS vars so they stay legible in both light and dark (the old hardcoded white vanished on white).
const W = 760;
const H = 260;
const PAD = { t: 14, r: 16, b: 26, l: 60 };

// The default categorical palette, assigned per series group. A series can override its own colour
// (config.series.*.color); exported so the editor shows the same default swatches.
export const GRAPH_PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
const TOTAL = 'rgb(var(--graph-ink) / 0.9)'; // neutral, theme-aware (see app.css)

const DEFAULT_STYLE: ChartStyle = { fill: false, stacked: false };

const fmtTime = (t: number) => new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const fmtDate = (t: number) => new Date(t).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

// Axis ink via a CSS var so it flips with the theme (var() only resolves in `style`, never in an
// SVG presentation attribute - so grid/labels go through a style prop, not stroke="..."/fill="...").
const axis = (a: number) => `rgb(var(--graph-axis) / ${a})`;

function fmtValue(v: number, format: GraphSeriesFormat, unit: string | null): string {
    if (format === 'rate') return formatRate(v);
    if (format === 'util') return `${v.toFixed(v < 10 ? 1 : 0)}%`;
    if (format === 'ms') return `${v < 10 ? v.toFixed(1) : Math.round(v)} ms`;
    return `${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ` ${unit}` : ''}`;
}

type Line = { key: string; label: string; color: string; dashed: boolean; emphasized: boolean; format: GraphSeriesFormat; unit: string | null; values: (number | null)[] };
// A resolved thing to draw: its stroke path top values, an optional lower edge (stacked/fill area)
// and the scale it's plotted against. `filled` draws the area under/within it.
type Drawn = { line: Line; top: (number | null)[]; base: number[] | null; sc: (v: number) => number; filled: boolean };

export function GraphChart({ data, style, svgRef }: { data: GraphData; style?: ChartStyle | null; svgRef?: React.Ref<SVGSVGElement> }) {
    const [hover, setHover] = useState<number | null>(null);
    const wrap = useRef<HTMLDivElement>(null);

    const { fill, stacked } = style ?? DEFAULT_STYLE;
    const palette = style?.palette?.length ? style.palette : GRAPH_PALETTE;
    const colorMode = style?.color_mode ?? 'group';

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
        // Default colour assignment (a series can always pin its own). 'group' shares one colour
        // across an interface's in + out (out drawn dashed to tell them apart); 'series' gives every
        // series its own colour so none hide under another. Both wrap when series outnumber colours.
        const groupIdx = new Map<string, number>();
        const defaultColor = (group: string, i: number) => {
            if (colorMode === 'series') return palette[i % palette.length];
            if (!groupIdx.has(group)) groupIdx.set(group, groupIdx.size);
            return palette[(groupIdx.get(group) ?? 0) % palette.length];
        };
        const out: Line[] = data.series.map((s, i) => ({
            key: `${s.group}:${i}`, label: s.label, color: s.color ?? defaultColor(s.group, i),
            dashed: s.dashed, emphasized: false, format: s.format, unit: s.unit, values: s.values,
        }));
        if (data.total) out.push({ key: 'total', label: 'Total', color: TOTAL, dashed: false, emphasized: true, format: leftFmt.format, unit: leftFmt.unit, values: data.total });
        return out;
    }, [data, leftFmt, palette, colorMode]);

    // Split by scale. Latency gets its own right-hand axis, but only when there's a traffic series
    // to sit alongside; an all-latency graph plots ms on the left.
    const mains = useMemo(() => lines.filter((l) => l.format !== 'ms' && !l.emphasized), [lines]);
    const msOnly = useMemo(() => lines.filter((l) => l.format === 'ms'), [lines]);
    const totalLine = useMemo(() => lines.find((l) => l.emphasized) ?? null, [lines]);
    const dualMs = msOnly.length > 0 && (mains.length > 0 || totalLine != null);

    const nb = times.length;
    const tMin = nb ? Math.min(...times) : 0;
    const tMax = nb ? Math.max(...times) : 1;
    const tSpan = Math.max(1, tMax - tMin);

    const padR = dualMs ? 46 : PAD.r; // room for the right-hand ms axis labels
    const x = (t: number) => PAD.l + ((t - tMin) / tSpan) * (W - PAD.l - padR);

    // Build the draw list (and the left-scale peak) together, because stacking changes both.
    const { drawn, yMax, msMax } = useMemo(() => {
        const msMaxLocal = Math.max(1, ...msOnly.flatMap((l) => l.values.map((v) => v ?? 0))) * 1.15;

        // Left-axis peak: the stack top when stacked, else the tallest single main/total series.
        let leftPeak: number;
        const items: Omit<Drawn, 'sc'>[] = [];
        if (stacked && mains.length > 0) {
            const running = new Array(nb).fill(0);
            for (const l of mains) {
                const top = running.map((b, i) => b + (l.values[i] ?? 0));
                items.push({ line: l, top, base: running.slice(), filled: true });
                for (let i = 0; i < nb; i++) running[i] = top[i];
            }
            leftPeak = Math.max(1, ...running); // stack top = the implicit total
        } else {
            for (const l of mains) items.push({ line: l, top: l.values, base: null, filled: fill });
            if (totalLine) items.push({ line: totalLine, top: totalLine.values, base: null, filled: false });
            const src = mains.length || totalLine ? [...mains, ...(totalLine ? [totalLine] : [])] : msOnly;
            leftPeak = Math.max(1, ...src.flatMap((l) => l.values.map((v) => v ?? 0)));
        }
        const yMaxLocal = leftPeak * 1.15;

        const yFn = (v: number) => PAD.t + (1 - v / yMaxLocal) * (H - PAD.t - PAD.b);
        const yMsFn = (v: number) => PAD.t + (1 - v / msMaxLocal) * (H - PAD.t - PAD.b);
        // ms overlay only takes the right scale when a left-axis series shares the chart.
        const msScale = dualMs ? yMsFn : yFn;
        for (const l of msOnly) items.push({ line: l, top: l.values, base: null, filled: false });

        const list: Drawn[] = items.map((it) => ({ ...it, sc: it.line.format === 'ms' ? msScale : yFn }));
        return { drawn: list, yMax: yMaxLocal, msMax: msMaxLocal };
    }, [mains, msOnly, totalLine, stacked, fill, dualMs, nb]);

    // A stroke path along `top` (breaks on nulls, so gaps stay gaps).
    const strokePath = (values: (number | null)[], sc: (v: number) => number) => {
        let d = '';
        let pen = false;
        values.forEach((v, i) => {
            if (v == null || times[i] === undefined) { pen = false; return; }
            d += `${pen ? 'L' : 'M'}${x(times[i]).toFixed(1)} ${sc(v).toFixed(1)} `;
            pen = true;
        });
        return d.trim();
    };

    // A filled area. Stacked bands close between `top` and `base` (both gap-free); a plain fill drops
    // to the baseline (y of 0), segmented so a null in the series leaves a hole rather than a bridge.
    const areaPath = (d: Drawn) => {
        const y0 = d.sc(0);
        if (d.base) {
            let path = '';
            d.top.forEach((v, i) => { path += `${i ? 'L' : 'M'}${x(times[i]).toFixed(1)} ${d.sc((v as number)).toFixed(1)} `; });
            for (let i = d.base.length - 1; i >= 0; i--) path += `L${x(times[i]).toFixed(1)} ${d.sc(d.base[i]).toFixed(1)} `;
            return path + 'Z';
        }
        let path = '';
        let i = 0;
        while (i < d.top.length) {
            if (d.top[i] == null || times[i] === undefined) { i++; continue; }
            const seg: number[] = [];
            while (i < d.top.length && d.top[i] != null && times[i] !== undefined) { seg.push(i); i++; }
            path += `M${x(times[seg[0]]).toFixed(1)} ${y0.toFixed(1)} `;
            for (const k of seg) path += `L${x(times[k]).toFixed(1)} ${d.sc(d.top[k] as number).toFixed(1)} `;
            path += `L${x(times[seg[seg.length - 1]]).toFixed(1)} ${y0.toFixed(1)} Z `;
        }
        return path.trim();
    };

    // Fractions 0..1; both axes share the plot height (0 at bottom), left labelled in the traffic
    // unit, right in ms when latency is overlaid.
    const ticks = [0, 0.5, 1];
    const noData = drawn.length === 0 || drawn.every((d) => d.line.values.every((v) => v == null));

    function onMove(e: React.MouseEvent) {
        const rect = wrap.current?.getBoundingClientRect();
        if (!rect || times.length === 0) return;
        const px = ((e.clientX - rect.left) / rect.width) * W;
        const frac = Math.min(1, Math.max(0, (px - PAD.l) / (W - PAD.l - padR)));
        setHover(Math.round(frac * (times.length - 1)));
    }

    const hi = hover != null && hover >= 0 && hover < times.length ? hover : null;

    return (
        <div className="space-y-2">
            <div ref={wrap} className="relative" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
                <svg ref={svgRef} viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="Custom graph">
                    {ticks.map((f, i) => {
                        const yp = PAD.t + (1 - f) * (H - PAD.t - PAD.b);
                        return (
                            <g key={i}>
                                <line x1={PAD.l} x2={W - padR} y1={yp} y2={yp} style={{ stroke: axis(0.1) }} strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                <text x={PAD.l - 6} y={yp + 3} textAnchor="end" style={{ fill: axis(0.45) }} fontSize={10}>{fmtY(yMax * f)}</text>
                                {dualMs && (
                                    <text x={W - padR + 6} y={yp + 3} textAnchor="start" style={{ fill: axis(0.4) }} fontSize={10}>{fmtValue(msMax * f, 'ms', null)}</text>
                                )}
                            </g>
                        );
                    })}

                    {!noData && drawn.map((d) => (d.filled ? (
                        <path key={`fill-${d.line.key}`} d={areaPath(d)} style={{ fill: d.line.color }} fillOpacity={0.16} stroke="none" />
                    ) : null))}

                    {!noData && drawn.map((d) => (
                        <path key={d.line.key} d={strokePath(d.top, d.sc)} fill="none" style={{ stroke: d.line.color }}
                            strokeWidth={d.line.emphasized ? 2.4 : 1.8} strokeDasharray={d.line.dashed ? '5 3' : undefined}
                            strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" opacity={d.line.emphasized ? 0.95 : 0.9} />
                    ))}

                    {hi != null && !noData && (
                        <g>
                            <line x1={x(times[hi])} x2={x(times[hi])} y1={PAD.t} y2={H - PAD.b} style={{ stroke: axis(0.3) }} strokeWidth={1} vectorEffect="non-scaling-stroke" />
                            {drawn.map((d) => (d.top[hi] != null ? (
                                <circle key={d.line.key} cx={x(times[hi])} cy={d.sc(d.top[hi] as number)} r={2.6} style={{ fill: d.line.color, stroke: 'var(--color-surface)' }} strokeWidth={1} />
                            ) : null))}
                        </g>
                    )}

                    <text x={PAD.l} y={H - 6} textAnchor="start" style={{ fill: axis(0.45) }} fontSize={10}>{times.length ? fmtTime(tMin) : ''}</text>
                    <text x={W - padR} y={H - 6} textAnchor="end" style={{ fill: axis(0.45) }} fontSize={10}>{times.length ? fmtTime(tMax) : ''}</text>
                </svg>

                {noData && (
                    <div className="pointer-events-none absolute inset-0 grid place-items-center text-xs text-white/35">No data in this range yet.</div>
                )}

                {hi != null && !noData && (
                    <div className="pointer-events-none absolute top-2 rounded-lg bg-surface/95 px-2.5 py-1.5 text-[11px] shadow-[0_8px_24px_-8px_rgba(0,0,0,0.8)] ring-1 ring-white/10"
                        style={{ left: `${Math.min(72, (x(times[hi]) / W) * 100)}%` }}>
                        <div className="mb-1 font-mono text-[10px] text-white/45">{fmtDate(times[hi])}</div>
                        {drawn.map((d) => (
                            <div key={d.line.key} className="flex items-center gap-1.5">
                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: d.line.color }} />
                                <span className="min-w-0 flex-1 truncate text-white/60">{d.line.label}</span>
                                <span className="ml-2 font-mono tabular-nums text-white/85">{d.line.values[hi] != null ? fmtValue(d.line.values[hi] as number, d.line.format, d.line.unit) : '-'}</span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {drawn.length >= 2 && (
                <div className="flex flex-wrap gap-x-3 gap-y-1 px-1 text-[11px]">
                    {drawn.map((d) => (
                        <span key={d.line.key} className="flex items-center gap-1.5 text-white/60">
                            <span className="inline-block h-0.5 w-4 rounded" style={{ backgroundColor: d.line.color, opacity: d.line.dashed ? 0.6 : 1 }} />
                            {d.line.label}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
