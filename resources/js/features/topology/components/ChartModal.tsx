import { useMemo, useState, type MouseEvent } from 'react';
import { createPortal } from 'react-dom';
import { useQueries } from '@tanstack/react-query';
import { Pulse, X } from '@phosphor-icons/react';
import { useDeviceInterfaces } from '../api/getDeviceInterfaces';
import { parseUtc } from '../../../lib/parseUtc';
import { fetchDeviceSamples, deviceSampleKeys } from '../api/getDeviceSamples';
import { fetchInterfaceSamples, sampleKeys } from '../api/getInterfaceSamples';
import { formatRate } from '../../../lib/formatRate';
import type { InterfaceSample } from '../../../types';

const WINDOWS = [
    ['1h', 3600],
    ['6h', 21600],
    ['24h', 86400],
    ['7d', 604800],
    ['30d', 2592000],
] as const;

// Distinct series colours (Total first, then per-interface).
const COLORS = ['#34d399', '#38bdf8', '#f472b6', '#fbbf24', '#a78bfa', '#fb7185', '#22d3ee', '#84cc16', '#f97316', '#e879f9'];

const PAD = { t: 16, r: 16, b: 28, l: 64 };

type SourceKey = string; // 'total' | `if:${id}`
type Series = { key: SourceKey; label: string; color: string; points: { t: number; v: number }[] };

/** Total throughput of a sample (in + out), bits/sec. */
const total = (s: InterfaceSample) => (s.bps_in ?? 0) + (s.bps_out ?? 0);

/**
 * Full-screen throughput explorer (opened from the inspector graph). Pick the device
 * total and/or any interfaces - each renders as its own coloured line - over a chosen
 * window, with a hover tooltip reading every series at that point. Portalled to <body>
 * so it isn\'t clipped by the (transformed) inspector panel.
 */
export function ChartModal({
    deviceId,
    deviceName,
    onClose,
}: {
    deviceId: number;
    deviceName: string;
    hasSpeed?: boolean;
    onClose: () => void;
}) {
    const [windowSec, setWindowSec] = useState<number>(86400);
    const [selected, setSelected] = useState<Set<SourceKey>>(new Set(['total']));
    const [hover, setHover] = useState<number | null>(null); // hovered bucket index
    const { data: interfaces } = useDeviceInterfaces(deviceId);

    // The pick-list: Total + each interface.
    const sources = useMemo(() => {
        const list: { key: SourceKey; label: string }[] = [{ key: 'total', label: 'Total' }];
        for (const i of interfaces ?? []) list.push({ key: `if:${i.id}`, label: i.name });
        return list;
    }, [interfaces]);

    const selectedKeys = sources.filter((s) => selected.has(s.key)).map((s) => s.key);

    // One query per selected source (buckets align - same window/origin server-side).
    const results = useQueries({
        queries: selectedKeys.map((key) => {
            const isTotal = key === 'total';
            const id = isTotal ? deviceId : Number(key.slice(3));
            return {
                queryKey: isTotal ? deviceSampleKeys.series(id, windowSec) : sampleKeys.series(id, windowSec),
                queryFn: () => (isTotal ? fetchDeviceSamples(id, windowSec) : fetchInterfaceSamples(id, windowSec)),
                refetchInterval: 30_000,
            };
        }),
    });

    const loading = results.some((r) => r.isLoading);

    const series: Series[] = selectedKeys.map((key, idx) => {
        const samples = (results[idx]?.data ?? []) as InterfaceSample[];
        const src = sources.find((s) => s.key === key);
        return {
            key,
            label: src?.label ?? key,
            color: COLORS[sources.findIndex((s) => s.key === key) % COLORS.length],
            points: samples.map((s) => ({ t: parseUtc(s.ts), v: total(s) })).filter((p) => !Number.isNaN(p.t)),
        };
    });

    // Shared time + value domain across all series.
    const allPts = series.flatMap((s) => s.points);
    const tMin = allPts.length ? Math.min(...allPts.map((p) => p.t)) : 0;
    const tMax = allPts.length ? Math.max(...allPts.map((p) => p.t)) : 1;
    const tSpan = Math.max(1, tMax - tMin);
    const vMax = Math.max(1, ...allPts.map((p) => p.v)) * 1.12;

    // Union of bucket timestamps (sorted) - the x grid the tooltip snaps to.
    const buckets = useMemo(() => {
        const set = new Set<number>();
        for (const s of series) for (const p of s.points) set.add(p.t);
        return [...set].sort((a, b) => a - b);
    }, [series]);

    const W = 1000;
    const H = 460;
    const x = (t: number) => PAD.l + ((t - tMin) / tSpan) * (W - PAD.l - PAD.r);
    const y = (v: number) => PAD.t + (1 - v / vMax) * (H - PAD.t - PAD.b);
    const fmtTime = (t: number) => new Date(t).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

    const valueAt = (s: Series, t: number): number | null => s.points.find((p) => p.t === t)?.v ?? null;

    function onMove(e: MouseEvent<SVGSVGElement>) {
        if (buckets.length === 0) return;
        const rect = e.currentTarget.getBoundingClientRect();
        const px = ((e.clientX - rect.left) / rect.width) * W; // into viewBox space
        const t = tMin + ((px - PAD.l) / (W - PAD.l - PAD.r)) * tSpan;
        // nearest bucket
        let best = 0;
        for (let i = 1; i < buckets.length; i++) if (Math.abs(buckets[i] - t) < Math.abs(buckets[best] - t)) best = i;
        setHover(best);
    }

    const hoverT = hover !== null ? buckets[hover] : null;

    return createPortal(
        <div className="fixed inset-0 z-[60] grid place-items-center p-4 sm:p-8">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />

            <div className="relative flex h-full w-full max-w-6xl flex-col rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="relative flex min-h-0 flex-1 flex-col rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="absolute right-4 top-4 z-10 rounded-lg p-1 text-white/40 transition-colors duration-300 hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-5 w-5" />
                    </button>

                    <header className="mb-4 flex flex-wrap items-start justify-between gap-3 pr-10">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <Pulse weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Throughput</h2>
                                <p className="truncate text-xs text-white/40">{deviceName}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                            {WINDOWS.map(([labelText, secs]) => (
                                <button
                                    key={labelText}
                                    onClick={() => setWindowSec(secs)}
                                    className={`rounded-full px-2.5 py-0.5 transition-colors duration-300 ${windowSec === secs ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70'}`}
                                >
                                    {labelText}
                                </button>
                            ))}
                        </div>
                    </header>

                    {/* Source picker - multi-select chips, each in its series colour. */}
                    <div className="mb-3 flex flex-wrap gap-1.5">
                        {sources.map((s) => {
                            const on = selected.has(s.key);
                            const color = COLORS[sources.findIndex((x) => x.key === s.key) % COLORS.length];
                            return (
                                <button
                                    key={s.key}
                                    onClick={() =>
                                        setSelected((prev) => {
                                            const next = new Set(prev);
                                            next.has(s.key) ? next.delete(s.key) : next.add(s.key);
                                            if (next.size === 0) next.add('total'); // never empty
                                            return next;
                                        })
                                    }
                                    className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs ring-1 transition ${on ? 'bg-white/[0.06] text-white/90 ring-white/20' : 'text-white/45 ring-white/10 hover:text-white/70'}`}
                                >
                                    <span className="h-2 w-2 rounded-full" style={{ background: on ? color : 'transparent', boxShadow: on ? 'none' : `inset 0 0 0 1px ${color}` }} />
                                    {s.label}
                                </button>
                            );
                        })}
                    </div>

                    {/* Chart */}
                    <div className="relative min-h-0 flex-1">
                        {loading ? (
                            <div className="grid h-full place-items-center text-sm text-white/40">Loading...</div>
                        ) : allPts.length === 0 ? (
                            <div className="grid h-full place-items-center text-sm text-white/35">No samples in this window.</div>
                        ) : (
                            <svg viewBox={`0 0 ${W} ${H}`} className="h-full w-full" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
                                {[0, 0.25, 0.5, 0.75, 1].map((f, i) => {
                                    const v = vMax * (1 - f);
                                    return (
                                        <g key={i}>
                                            <line x1={PAD.l} x2={W - PAD.r} y1={PAD.t + f * (H - PAD.t - PAD.b)} y2={PAD.t + f * (H - PAD.t - PAD.b)} stroke="rgba(255,255,255,0.07)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                            <text x={PAD.l - 8} y={PAD.t + f * (H - PAD.t - PAD.b) + 3} textAnchor="end" className="fill-white/35" fontSize={11}>
                                                {formatRate(v)}
                                            </text>
                                        </g>
                                    );
                                })}

                                {series.map((s) => (
                                    <path
                                        key={s.key}
                                        d={s.points.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)}`).join(' ')}
                                        fill="none"
                                        stroke={s.color}
                                        strokeWidth={1.8}
                                        vectorEffect="non-scaling-stroke"
                                        strokeLinejoin="round"
                                    />
                                ))}

                                {/* Hover guide + points */}
                                {hoverT !== null && (
                                    <>
                                        <line x1={x(hoverT)} x2={x(hoverT)} y1={PAD.t} y2={H - PAD.b} stroke="rgba(255,255,255,0.25)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                        {series.map((s) => {
                                            const v = valueAt(s, hoverT);
                                            return v === null ? null : <circle key={s.key} cx={x(hoverT)} cy={y(v)} r={3} fill={s.color} />;
                                        })}
                                    </>
                                )}

                                <text x={PAD.l} y={H - 8} textAnchor="start" className="fill-white/35" fontSize={11}>
                                    {fmtTime(tMin)}
                                </text>
                                <text x={W - PAD.r} y={H - 8} textAnchor="end" className="fill-white/35" fontSize={11}>
                                    {fmtTime(tMax)}
                                </text>
                            </svg>
                        )}

                        {/* Tooltip */}
                        {hoverT !== null && (
                            <div
                                className="pointer-events-none absolute top-2 rounded-xl bg-surface-2/95 px-3 py-2 text-xs ring-1 ring-white/10"
                                style={{ left: `${(x(hoverT) / W) * 100}%`, transform: 'translateX(-50%)' }}
                            >
                                <div className="mb-1 text-[10px] uppercase tracking-wide text-white/40">{fmtTime(hoverT)}</div>
                                {series.map((s) => {
                                    const v = valueAt(s, hoverT);
                                    return (
                                        <div key={s.key} className="flex items-center gap-2 tabular-nums">
                                            <span className="h-2 w-2 rounded-full" style={{ background: s.color }} />
                                            <span className="min-w-0 flex-1 truncate text-white/70">{s.label}</span>
                                            <span className="text-white/90">{v === null ? '-' : formatRate(v)}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
