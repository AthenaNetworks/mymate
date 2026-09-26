import { memo, useEffect, useId, useMemo, useRef, useState, type PointerEvent as ReactPointerEvent, type Ref } from 'react';
import { fmtStamp, fmtTick, fmtValue, niceMax } from '../lib/format';
import { setHoverTime, useHoverTime } from '../lib/hoverSync';
import type { SeriesStats } from '../api/devicePage';

/**
 * The device page's time series chart. Hand-rolled SVG like the rest of the app's graphs
 * (GraphChart, the inspector charts), so it picks up the same axis ink tokens, the house
 * palette and the PNG export, with what a LibreNMS style page needs on top:
 *
 *  - a fixed x domain (the page range), so every graph on a tab lines up
 *  - a crosshair synced across all graphs through lib/hoverSync (only the overlay re-renders)
 *  - drag across the plot to zoom the whole page to that window
 *  - mirrored series (traffic out drawn below the axis), a faint min/max envelope, a faint
 *    previous-period overlay and horizontal reference lines (95th percentile)
 *  - a min / avg / max / last (/ 95th) legend table
 */

export interface ChartLine {
    id: string;
    label: string;
    color: string;
    values: (number | null)[];
    hi?: (number | null)[] | null; // per-bucket max, drawn as a faint envelope
    lo?: (number | null)[] | null;
    compare?: (number | null)[] | null; // previous period, same bucket positions
    mirrored?: boolean; // drawn below the axis
    area?: boolean;
    dashed?: boolean;
    stats?: SeriesStats | null;
}

export interface ChartRefLine {
    id: string;
    label: string;
    value: number;
    color: string;
    mirrored?: boolean;
}

interface Props {
    times: number[]; // bucket start, epoch ms
    stepMs: number;
    lines: ChartLine[];
    unit: string | null;
    domain: [number, number]; // epoch ms
    refs?: ChartRefLine[];
    height?: number;
    compact?: boolean;
    loading?: boolean;
    showEnvelope?: boolean;
    onZoom?: (fromMs: number, toMs: number) => void;
    svgRef?: Ref<SVGSVGElement>;
}

const axis = (a: number) => `rgb(var(--graph-axis) / ${a})`;

function useWidth() {
    const ref = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const ro = new ResizeObserver((entries) => setWidth(Math.round(entries[0].contentRect.width)));
        ro.observe(el);
        setWidth(Math.round(el.getBoundingClientRect().width));
        return () => ro.disconnect();
    }, []);
    return [ref, width] as const;
}

function extent(arrs: ((number | null)[] | null | undefined)[]): [number, number] | null {
    let lo = Infinity;
    let hi = -Infinity;
    for (const a of arrs) {
        if (!a) continue;
        for (const v of a) {
            if (v === null || !Number.isFinite(v)) continue;
            if (v < lo) lo = v;
            if (v > hi) hi = v;
        }
    }
    return lo === Infinity ? null : [lo, hi];
}

export function TimeChart(props: Props) {
    const { times, stepMs, lines, unit, domain, refs = [], height = 190, compact = false, loading = false, showEnvelope = true, onZoom, svgRef } = props;
    const [wrapRef, width] = useWidth();
    const [drag, setDrag] = useState<{ x0: number; x1: number } | null>(null);
    const clipId = `plot-${useId().replace(/:/g, '')}`;

    const pad = compact ? { t: 4, r: 2, b: 4, l: 2 } : { t: 10, r: 12, b: 22, l: 66 };
    const W = Math.max(width, 120);
    const H = height;
    const plotW = W - pad.l - pad.r;
    const plotH = H - pad.t - pad.b;
    const [d0, d1] = domain;
    const span = Math.max(1, d1 - d0);
    const x = (t: number) => pad.l + ((t - d0) / span) * plotW;
    const tAt = (px: number) => d0 + ((px - pad.l) / plotW) * span;

    // Y domain. Mirrored lines hang below zero; units that live below zero (dBm) or far from it
    // (temperature) autoscale instead of starting at 0.
    const scale = useMemo(() => {
        const up = lines.filter((l) => !l.mirrored);
        const down = lines.filter((l) => l.mirrored);
        const upExt = extent(up.flatMap((l) => [l.values, showEnvelope ? l.hi : null, showEnvelope ? l.lo : null, l.compare]).concat([refs.filter((r) => !r.mirrored).map((r) => r.value)]));
        const downExt = extent(down.flatMap((l) => [l.values, showEnvelope ? l.hi : null, l.compare]).concat([refs.filter((r) => r.mirrored).map((r) => r.value)]));

        if (down.length > 0) {
            const hi = niceMax(Math.max(upExt?.[1] ?? 0, 0));
            const lo = -niceMax(Math.max(downExt?.[1] ?? 0, 0));
            return { lo, hi, mirrored: true };
        }
        if (!upExt) return { lo: 0, hi: 1, mirrored: false };
        const [mn, mx] = upExt;
        const floating = mn < 0 || unit === 'C' || unit === 'dB' || unit === 'dBm';
        if (!floating) return { lo: 0, hi: unit === '%' && mx <= 100 && mx > 50 ? 100 : niceMax(mx * 1.05), mirrored: false };
        const pad5 = Math.max((mx - mn) * 0.1, Math.abs(mx) * 0.02, 1);
        const lo = Math.floor((mn - pad5) / 5) * 5;
        const hi = Math.ceil((mx + pad5) / 5) * 5;
        return { lo, hi: hi === lo ? lo + 5 : hi, mirrored: false };
    }, [lines, refs, unit, showEnvelope]);

    const y = (v: number) => pad.t + ((scale.hi - v) / (scale.hi - scale.lo)) * plotH;
    const sign = (l: { mirrored?: boolean }) => (l.mirrored ? -1 : 1);
    const baseline = y(Math.max(scale.lo, Math.min(0, scale.hi)));
    const cx = (i: number) => x(times[i] + stepMs / 2);

    const paths = useMemo(() => {
        const line = (vals: (number | null)[], s: number) => {
            let d = '';
            let pen = false;
            for (let i = 0; i < vals.length; i++) {
                const v = vals[i];
                if (v === null || times[i] === undefined) {
                    pen = false;
                    continue;
                }
                d += `${pen ? 'L' : 'M'}${cx(i).toFixed(1)} ${y(s * v).toFixed(1)}`;
                pen = true;
            }
            return d;
        };
        // area between `top` and `bottom` (or the baseline), split on gaps
        const band = (top: (number | null)[], bottom: (number | null)[] | null, s: number) => {
            let d = '';
            let i = 0;
            while (i < top.length) {
                if (top[i] === null) {
                    i++;
                    continue;
                }
                const seg: number[] = [];
                while (i < top.length && top[i] !== null) seg.push(i++);
                d += `M${cx(seg[0]).toFixed(1)} ${(bottom ? y(s * (bottom[seg[0]] ?? 0)) : baseline).toFixed(1)}`;
                for (const k of seg) d += `L${cx(k).toFixed(1)} ${y(s * (top[k] as number)).toFixed(1)}`;
                for (let j = seg.length - 1; j >= 0; j--) {
                    const k = seg[j];
                    d += `L${cx(k).toFixed(1)} ${(bottom ? y(s * (bottom[k] ?? top[k] ?? 0)) : baseline).toFixed(1)}`;
                }
                d += 'Z';
            }
            return d;
        };
        return lines.map((l) => ({
            id: l.id,
            line: line(l.values, sign(l)),
            area: l.area ? band(l.values, null, sign(l)) : '',
            env: showEnvelope && l.hi ? band(l.hi, l.lo ?? l.values, sign(l)) : '',
            compare: l.compare ? line(l.compare, sign(l)) : '',
        }));
    }, [lines, times, stepMs, W, H, d0, d1, scale, showEnvelope]);

    const yTicks = useMemo(() => {
        if (compact) return [];
        if (scale.mirrored) return [scale.hi, scale.hi / 2, 0, scale.lo / 2, scale.lo];
        return [0, 0.25, 0.5, 0.75, 1].map((f) => scale.lo + (scale.hi - scale.lo) * f);
    }, [scale, compact]);

    const xTicks = useMemo(() => {
        if (compact || plotW < 50) return [];
        const n = Math.max(2, Math.min(8, Math.floor(plotW / 110)));
        return Array.from({ length: n + 1 }, (_, i) => d0 + (span * i) / n);
    }, [compact, plotW, d0, span]);

    const empty = !loading && lines.every((l) => l.values.every((v) => v === null));

    function pointerX(e: ReactPointerEvent) {
        const r = (e.currentTarget as Element).getBoundingClientRect();
        return Math.min(pad.l + plotW, Math.max(pad.l, e.clientX - r.left));
    }

    return (
        <div ref={wrapRef} className="relative w-full select-none" style={{ height: H }}>
            {width > 0 && (
                <svg ref={svgRef} viewBox={`0 0 ${W} ${H}`} width={W} height={H} className="block" role="img">
                    {yTicks.map((v, i) => (
                        <g key={i}>
                            <line x1={pad.l} x2={pad.l + plotW} y1={y(v)} y2={y(v)} style={{ stroke: axis(v === 0 && scale.mirrored ? 0.25 : 0.08) }} strokeWidth={1} />
                            <text x={pad.l - 6} y={y(v) + 3} textAnchor="end" fontSize={10} style={{ fill: axis(0.45) }}>
                                {fmtValue(scale.mirrored ? Math.abs(v) : v, unit)}
                            </text>
                        </g>
                    ))}
                    {xTicks.map((t, i) => (
                        <text key={i} x={x(t)} y={H - 6} fontSize={10} textAnchor={i === 0 ? 'start' : i === xTicks.length - 1 ? 'end' : 'middle'} style={{ fill: axis(0.4) }}>
                            {fmtTick(t, span / 1000)}
                        </text>
                    ))}

                    <defs>
                        <clipPath id={clipId}>
                            <rect x={pad.l} y={pad.t - 2} width={plotW} height={plotH + 4} />
                        </clipPath>
                    </defs>
                    <g clipPath={`url(#${clipId})`}>
                        {paths.map((p, i) =>
                            p.env ? <path key={`env-${p.id}`} d={p.env} style={{ fill: lines[i].color }} fillOpacity={0.1} stroke="none" /> : null,
                        )}
                        {paths.map((p, i) =>
                            p.area ? <path key={`area-${p.id}`} d={p.area} style={{ fill: lines[i].color }} fillOpacity={0.22} stroke="none" /> : null,
                        )}
                        {paths.map((p, i) =>
                            p.compare ? (
                                <path key={`cmp-${p.id}`} d={p.compare} fill="none" style={{ stroke: lines[i].color }} strokeOpacity={0.4} strokeWidth={1} strokeDasharray="2 3" />
                            ) : null,
                        )}
                        {paths.map((p, i) => (
                            <path
                                key={`line-${p.id}`}
                                d={p.line}
                                fill="none"
                                style={{ stroke: lines[i].color }}
                                strokeWidth={compact ? 1.3 : 1.5}
                                strokeDasharray={lines[i].dashed ? '5 3' : undefined}
                                strokeLinejoin="round"
                            />
                        ))}
                        {refs.map((r) => {
                            const yy = y((r.mirrored ? -1 : 1) * r.value);
                            if (yy < pad.t - 1 || yy > pad.t + plotH + 1) return null;
                            return (
                                <g key={r.id}>
                                    <line x1={pad.l} x2={pad.l + plotW} y1={yy} y2={yy} style={{ stroke: r.color }} strokeWidth={1} strokeDasharray="6 3" />
                                    {!compact && (
                                        <text x={pad.l + plotW - 4} y={r.mirrored ? yy + 11 : yy - 4} textAnchor="end" fontSize={10} style={{ fill: r.color }}>
                                            {r.label} {fmtValue(r.value, unit)}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </g>

                    {drag && (
                        <rect x={Math.min(drag.x0, drag.x1)} y={pad.t} width={Math.abs(drag.x1 - drag.x0)} height={plotH} style={{ fill: axis(0.12), stroke: axis(0.3) }} strokeWidth={1} />
                    )}

                    <rect
                        x={pad.l}
                        y={pad.t}
                        width={plotW}
                        height={plotH}
                        fill="transparent"
                        style={{ cursor: onZoom ? 'crosshair' : 'default', touchAction: 'pan-y' }}
                        onPointerMove={(e) => {
                            const px = pointerX(e);
                            setHoverTime(tAt(px));
                            if (drag) setDrag({ ...drag, x1: px });
                        }}
                        onPointerLeave={() => setHoverTime(null)}
                        onPointerDown={(e) => {
                            if (!onZoom || e.button !== 0) return;
                            (e.currentTarget as Element).setPointerCapture(e.pointerId);
                            const px = pointerX(e);
                            setDrag({ x0: px, x1: px });
                        }}
                        onPointerUp={() => {
                            if (drag && onZoom && Math.abs(drag.x1 - drag.x0) > 4) {
                                const a = tAt(Math.min(drag.x0, drag.x1));
                                const b = tAt(Math.max(drag.x0, drag.x1));
                                onZoom(a, b);
                            }
                            setDrag(null);
                        }}
                        onPointerCancel={() => setDrag(null)}
                    />
                </svg>
            )}

            {width > 0 && (
                <Crosshair times={times} stepMs={stepMs} lines={lines} unit={unit} x={x} y={y} cx={cx} top={pad.t} bottom={pad.t + plotH} left={pad.l} right={pad.l + plotW} W={W} H={H} compact={compact} />
            )}

            {(loading || empty) && (
                <div className="pointer-events-none absolute inset-0 grid place-items-center text-xs text-white/35">
                    {loading ? 'Loading...' : 'No data in this range.'}
                </div>
            )}
        </div>
    );
}

/** The synced crosshair + value tooltip. Subscribes to the shared hover time on its own. */
const Crosshair = memo(function Crosshair({
    times, stepMs, lines, unit, x, y, cx, top, bottom, left, right, W, H, compact,
}: {
    times: number[];
    stepMs: number;
    lines: ChartLine[];
    unit: string | null;
    x: (t: number) => number;
    y: (v: number) => number;
    cx: (i: number) => number;
    top: number;
    bottom: number;
    left: number;
    right: number;
    W: number;
    H: number;
    compact: boolean;
}) {
    const t = useHoverTime();
    if (t === null || times.length === 0 || stepMs <= 0) return null;
    const px = x(t);
    if (px < left || px > right) return null;
    const i = Math.min(times.length - 1, Math.max(0, Math.floor((t - times[0]) / stepMs)));
    const bucketT = times[i];
    const flip = px / W > 0.6;

    return (
        <>
            <svg className="pointer-events-none absolute inset-0" width={W} height={H} viewBox={`0 0 ${W} ${H}`}>
                <line x1={px} x2={px} y1={top} y2={bottom} style={{ stroke: axis(0.35) }} strokeWidth={1} />
                {lines.map((l) =>
                    l.values[i] !== null && l.values[i] !== undefined ? (
                        <circle key={l.id} cx={cx(i)} cy={y((l.mirrored ? -1 : 1) * (l.values[i] as number))} r={2.6} style={{ fill: l.color, stroke: 'var(--color-surface)' }} strokeWidth={1} />
                    ) : null,
                )}
            </svg>
            {!compact && (
                <div
                    className="pointer-events-none absolute top-1 z-10 min-w-[10rem] rounded-lg bg-surface/95 px-2.5 py-1.5 text-[11px] shadow-[0_8px_24px_-8px_rgba(0,0,0,0.8)] ring-1 ring-white/10"
                    style={flip ? { right: `${W - px + 10}px` } : { left: `${px + 10}px` }}
                >
                    <div className="mb-1 font-mono text-[10px] text-white/45">{fmtStamp(bucketT)}</div>
                    {lines.map((l) => (
                        <div key={l.id} className="flex items-center gap-1.5">
                            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: l.color }} />
                            <span className="min-w-0 flex-1 truncate text-white/60">{l.label}</span>
                            <span className="ml-2 font-mono tabular-nums text-white/85">{fmtValue(l.values[i] ?? null, unit)}</span>
                            {l.compare && <span className="ml-1 font-mono tabular-nums text-white/35">{fmtValue(l.compare[i] ?? null, unit)}</span>}
                        </div>
                    ))}
                </div>
            )}
        </>
    );
});

/** LibreNMS style legend: one row per series with min / avg / max / last (and 95th). */
export function ChartLegend({ lines, unit }: { lines: ChartLine[]; unit: string | null }) {
    const withP95 = lines.some((l) => l.stats?.p95 !== undefined && l.stats?.p95 !== null);
    const withPeak = lines.some((l) => l.stats?.peak !== null && l.stats?.peak !== undefined && l.stats.peak !== l.stats.max);
    const cell = 'px-2 py-0.5 text-right font-mono tabular-nums';
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-[11px]">
                <thead>
                    <tr className="text-[10px] uppercase tracking-[0.14em] text-white/30">
                        <th className="px-2 py-0.5 text-left font-medium" />
                        <th className="px-2 py-0.5 text-right font-medium">Min</th>
                        <th className="px-2 py-0.5 text-right font-medium">Avg</th>
                        <th className="px-2 py-0.5 text-right font-medium">Max</th>
                        {withPeak && <th className="px-2 py-0.5 text-right font-medium" title="Highest single sample (the max the rollups keep)">Peak</th>}
                        <th className="px-2 py-0.5 text-right font-medium">Last</th>
                        {withP95 && <th className="px-2 py-0.5 text-right font-medium" title="95th percentile of 5 minute averages over this range">95th</th>}
                    </tr>
                </thead>
                <tbody>
                    {lines.map((l) => (
                        <tr key={l.id} className="text-white/70">
                            <td className="max-w-[16rem] truncate px-2 py-0.5">
                                <span className="mr-1.5 inline-block h-0.5 w-3 rounded align-middle" style={{ backgroundColor: l.color }} />
                                {l.label}
                            </td>
                            <td className={cell}>{fmtValue(l.stats?.min ?? null, unit)}</td>
                            <td className={cell}>{fmtValue(l.stats?.avg ?? null, unit)}</td>
                            <td className={cell}>{fmtValue(l.stats?.max ?? null, unit)}</td>
                            {withPeak && <td className={`${cell} text-white/45`}>{fmtValue(l.stats?.peak ?? null, unit)}</td>}
                            <td className={cell}>{fmtValue(l.stats?.last ?? null, unit)}</td>
                            {withP95 && <td className={`${cell} text-amber-300/90`}>{fmtValue(l.stats?.p95 ?? null, unit)}</td>}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
