import { useEffect, useMemo, useState, type MouseEvent } from 'react';
import { createPortal } from 'react-dom';
import { Heartbeat, X } from '@phosphor-icons/react';
import { useDeviceMetricSamples } from '../api/getDeviceMetricSamples';
import { useDevicePingSamples } from '../api/getDevicePingSamples';
import { parseUtc } from '../../../lib/parseUtc';
import { HEALTH_METRICS, healthPoints, type HealthMetric } from '../lib/healthMetrics';

const WINDOWS = [
    ['1h', 3600],
    ['6h', 21600],
    ['24h', 86400],
    ['7d', 604800],
    ['30d', 2592000],
] as const;

const PAD = { t: 16, r: 16, b: 28, l: 64 };

/**
 * Full-screen health explorer (opened from the inspector's Health section) - the sibling of the
 * throughput ChartModal. Health metrics mix units (%, ms, °C, dBm, counts), so rather than
 * overlaying them on a meaningless shared axis the chips pick ONE metric at a time and it gets a
 * real, labelled y-axis in its own unit. Same time windows and hover tooltip as the throughput
 * modal. Works for ping-only devices too (just latency / loss / jitter).
 */
export function HealthChartModal({ deviceId, deviceName, onClose }: { deviceId: number; deviceName: string; onClose: () => void }) {
    const [windowSec, setWindowSec] = useState<number>(86400);
    const [metricKey, setMetricKey] = useState<string | null>(null);
    const [hover, setHover] = useState<number | null>(null); // hovered point index

    const metricsQ = useDeviceMetricSamples(deviceId, windowSec);
    const pingQ = useDevicePingSamples(deviceId, windowSec);
    const loading = metricsQ.isLoading || pingQ.isLoading;
    const metrics = metricsQ.data ?? [];
    const ping = pingQ.data ?? [];

    // Esc closes, matching the app's other dialogs.
    useEffect(() => {
        function onKey(e: KeyboardEvent): void {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    // Only metrics with at least one sample in this window are offered.
    const available = useMemo(
        () =>
            HEALTH_METRICS.map((m) => ({ metric: m, points: healthPoints(m, ping, metrics, parseUtc) })).filter((e) => e.points.length > 0),
        [ping, metrics],
    );

    // Stick with the operator's pick while it has data; otherwise fall back to the first metric.
    const active = available.find((e) => e.metric.key === metricKey) ?? available[0] ?? null;
    const metric: HealthMetric | null = active?.metric ?? null;
    const points = active?.points ?? [];

    // Domain: the metric's own unit. Zero-based for %/ms/counts; padded min..max for the
    // signed/offset ones (dBm, dB, °C) so a -60 dBm signal isn't a flat line at the top.
    const tMin = points.length ? points[0].t : 0;
    const tMax = points.length ? points[points.length - 1].t : 1;
    const tSpan = Math.max(1, tMax - tMin);
    const rawMin = points.length ? Math.min(...points.map((p) => p.v)) : 0;
    const rawMax = points.length ? Math.max(...points.map((p) => p.v)) : 1;
    let vMin = 0;
    let vMax = Math.max(1, rawMax) * 1.12;
    if (metric && !metric.zeroBased) {
        const pad = Math.max(1, (rawMax - rawMin) * 0.15);
        vMin = rawMin - pad;
        vMax = rawMax + pad;
    }
    const vSpan = Math.max(1e-9, vMax - vMin);

    const W = 1000;
    const H = 460;
    const plotH = H - PAD.t - PAD.b;
    const x = (t: number) => PAD.l + ((t - tMin) / tSpan) * (W - PAD.l - PAD.r);
    const y = (v: number) => PAD.t + (1 - (v - vMin) / vSpan) * plotH;
    const fmtTime = (t: number) => new Date(t).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

    function onMove(e: MouseEvent<SVGSVGElement>) {
        if (points.length === 0) return;
        const rect = e.currentTarget.getBoundingClientRect();
        const px = ((e.clientX - rect.left) / rect.width) * W; // into viewBox space
        const t = tMin + ((px - PAD.l) / (W - PAD.l - PAD.r)) * tSpan;
        let best = 0;
        for (let i = 1; i < points.length; i++) if (Math.abs(points[i].t - t) < Math.abs(points[best].t - t)) best = i;
        setHover(best);
    }

    const hoverPt = hover !== null && hover < points.length ? points[hover] : null;

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
                                <Heartbeat weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Health</h2>
                                <p className="truncate text-xs text-white/40">{deviceName}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                            {WINDOWS.map(([labelText, secs]) => (
                                <button
                                    key={labelText}
                                    onClick={() => { setWindowSec(secs); setHover(null); }}
                                    className={`rounded-full px-2.5 py-0.5 transition-colors duration-300 ${windowSec === secs ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70'}`}
                                >
                                    {labelText}
                                </button>
                            ))}
                        </div>
                    </header>

                    {/* Metric picker - one at a time, each chip in its metric colour. */}
                    <div className="mb-3 flex flex-wrap gap-1.5">
                        {available.map(({ metric: m }) => {
                            const on = metric?.key === m.key;
                            return (
                                <button
                                    key={m.key}
                                    onClick={() => { setMetricKey(m.key); setHover(null); }}
                                    className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs ring-1 transition ${on ? 'bg-white/[0.06] text-white/90 ring-white/20' : 'text-white/45 ring-white/10 hover:text-white/70'}`}
                                >
                                    <span className="h-2 w-2 rounded-full" style={{ background: on ? m.color : 'transparent', boxShadow: on ? 'none' : `inset 0 0 0 1px ${m.color}` }} />
                                    {m.label}
                                </button>
                            );
                        })}
                    </div>

                    {/* Chart */}
                    <div className="relative min-h-0 flex-1">
                        {loading ? (
                            <div className="grid h-full place-items-center text-sm text-white/40">Loading...</div>
                        ) : metric === null ? (
                            <div className="grid h-full place-items-center text-sm text-white/35">No health history in this window - collected on the next sweep.</div>
                        ) : (
                            <svg viewBox={`0 0 ${W} ${H}`} className="h-full w-full" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
                                {[0, 0.25, 0.5, 0.75, 1].map((f, i) => {
                                    const v = vMax - f * vSpan;
                                    return (
                                        <g key={i}>
                                            <line x1={PAD.l} x2={W - PAD.r} y1={PAD.t + f * plotH} y2={PAD.t + f * plotH} stroke="rgba(255,255,255,0.07)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                            <text x={PAD.l - 8} y={PAD.t + f * plotH + 3} textAnchor="end" className="fill-white/35" fontSize={11}>
                                                {metric.format(v)}
                                            </text>
                                        </g>
                                    );
                                })}

                                <path
                                    d={points.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)}`).join(' ')}
                                    fill="none"
                                    stroke={metric.color}
                                    strokeWidth={1.8}
                                    vectorEffect="non-scaling-stroke"
                                    strokeLinejoin="round"
                                />

                                {hoverPt !== null && (
                                    <>
                                        <line x1={x(hoverPt.t)} x2={x(hoverPt.t)} y1={PAD.t} y2={H - PAD.b} stroke="rgba(255,255,255,0.25)" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                                        <circle cx={x(hoverPt.t)} cy={y(hoverPt.v)} r={3} fill={metric.color} />
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
                        {metric !== null && hoverPt !== null && (
                            <div
                                className="pointer-events-none absolute top-2 rounded-xl bg-surface-2/95 px-3 py-2 text-xs ring-1 ring-white/10"
                                style={{ left: `${(x(hoverPt.t) / W) * 100}%`, transform: 'translateX(-50%)' }}
                            >
                                <div className="mb-1 text-[10px] uppercase tracking-wide text-white/40">{fmtTime(hoverPt.t)}</div>
                                <div className="flex items-center gap-2 tabular-nums">
                                    <span className="h-2 w-2 rounded-full" style={{ background: metric.color }} />
                                    <span className="min-w-0 flex-1 truncate text-white/70">{metric.label}</span>
                                    <span className="text-white/90">{metric.format(hoverPt.v)}</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
