import { useId, useState } from 'react';
import { formatRate } from '../../../lib/formatRate';
import type { InterfaceSample } from '../../../types';

// Fixed drawing space; the SVG scales to its container (uniform, crisp strokes via
// vectorEffect). Colours mirror the in/out semantics used elsewhere.
const W = 560;
const H = 150;
const PAD = { t: 12, r: 14, b: 22, l: 48 };
const IN = '#34d399'; // emerald - inbound
const OUT = '#38bdf8'; // sky - outbound

type Mode = 'util' | 'rate';
type Pt = { t: number; v: number };

const KEYS: Record<Mode, { in: keyof InterfaceSample; out: keyof InterfaceSample }> = {
    util: { in: 'util_in', out: 'util_out' },
    rate: { in: 'bps_in', out: 'bps_out' },
};

function points(samples: InterfaceSample[], key: keyof InterfaceSample): Pt[] {
    return samples
        .map((s) => ({ t: Date.parse(s.ts), v: s[key] as number | null }))
        .filter((p): p is Pt => p.v !== null && !Number.isNaN(p.t));
}

const fmtTime = (t: number) => new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

export function InterfaceChart({
    samples,
    loading,
    mode: modeProp,
    onModeChange,
    hasSpeed = true,
}: {
    samples?: InterfaceSample[];
    loading?: boolean;
    // Optionally controlled: when both are supplied (DeviceInspector -> per-device store),
    // the toggle is driven from outside; otherwise it falls back to internal state so
    // uncontrolled callers (LinkHistoryDialog) keep working unchanged.
    mode?: Mode;
    onModeChange?: (m: Mode) => void;
    // When false (no known speed) util% is meaningless -> force Throughput and hide the
    // Util% toggle; the throughput axis already auto-scales to the data\'s min/max.
    hasSpeed?: boolean;
}) {
    const gradId = useId();
    const [internalMode, setInternalMode] = useState<Mode>('util');
    const requested = modeProp ?? internalMode;
    const mode: Mode = hasSpeed ? requested : 'rate';
    const setMode = onModeChange ?? setInternalMode;
    const data = samples ?? [];

    if (loading) {
        return <div className="grid h-[140px] place-items-center text-xs text-white/40">Loading...</div>;
    }
    if (data.length === 0) {
        return (
            <div className="grid h-[140px] place-items-center px-4 text-center text-xs text-white/35">
                No history yet - samples accrue as polling runs.
            </div>
        );
    }

    const keys = KEYS[mode];
    // Throughput auto-scales (bps -> kbps/Mbps/Gbps) via formatRate; util is a 0-100 %.
    const fmtY = (v: number) => (mode === 'util' ? `${v.toFixed(v < 10 ? 1 : 0)}%` : formatRate(v));

    const times = data.map((s) => Date.parse(s.ts)).filter((t) => !Number.isNaN(t));
    const tMin = Math.min(...times);
    const tMax = Math.max(...times);
    const tSpan = Math.max(1, tMax - tMin);
    const peak = Math.max(1, ...data.flatMap((s) => [Number(s[keys.in] ?? 0), Number(s[keys.out] ?? 0)]));
    const yMax = peak * 1.15;

    const x = (t: number) => PAD.l + ((t - tMin) / tSpan) * (W - PAD.l - PAD.r);
    const y = (v: number) => PAD.t + (1 - v / yMax) * (H - PAD.t - PAD.b);

    const line = (pts: Pt[]) =>
        pts.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)}`).join(' ');

    const inPts = points(data, keys.in);
    const outPts = points(data, keys.out);
    const inLine = line(inPts);
    const inArea =
        inPts.length > 0
            ? `${inLine} L${x(inPts[inPts.length - 1].t).toFixed(1)} ${y(0).toFixed(1)} L${x(inPts[0].t).toFixed(1)} ${y(0).toFixed(1)} Z`
            : '';

    const ticks = [0, yMax / 2, yMax];
    const pill = (active: boolean) =>
        `rounded-full px-2 py-0.5 text-[10px] font-medium transition-colors duration-200 ${
            active ? 'bg-white/10 text-white/85 ring-1 ring-white/15' : 'text-white/40 hover:text-white/70'
        }`;

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-end gap-1">
                {hasSpeed ? (
                    <>
                        <button type="button" onClick={() => setMode('util')} className={pill(mode === 'util')}>
                            Util %
                        </button>
                        <button type="button" onClick={() => setMode('rate')} className={pill(mode === 'rate')}>
                            Throughput
                        </button>
                    </>
                ) : (
                    // No known speed -> util% can\'t be computed; show throughput only.
                    <span className="text-[10px] font-medium text-white/40" title="No interface speed set - utilisation % unavailable">
                        Throughput
                    </span>
                )}
            </div>

            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={mode === 'util' ? 'Utilisation history' : 'Throughput history'}>
                <defs>
                    <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={IN} stopOpacity="0.28" />
                        <stop offset="100%" stopColor={IN} stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* gridlines + y labels (auto-scaled unit) */}
                {ticks.map((v, i) => (
                    <g key={i}>
                        <line
                            x1={PAD.l}
                            x2={W - PAD.r}
                            y1={y(v)}
                            y2={y(v)}
                            stroke="rgba(255,255,255,0.08)"
                            strokeWidth={1}
                            vectorEffect="non-scaling-stroke"
                        />
                        <text x={PAD.l - 6} y={y(v) + 3} textAnchor="end" className="fill-white/35" fontSize={10}>
                            {fmtY(v)}
                        </text>
                    </g>
                ))}

                {/* inbound area + lines */}
                {inArea && <path d={inArea} fill={`url(#${gradId})`} stroke="none" />}
                {inLine && (
                    <path d={inLine} fill="none" stroke={IN} strokeWidth={1.6} vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
                )}
                {outPts.length > 0 && (
                    <path d={line(outPts)} fill="none" stroke={OUT} strokeWidth={1.6} vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
                )}

                {/* time labels */}
                <text x={PAD.l} y={H - 6} textAnchor="start" className="fill-white/35" fontSize={10}>
                    {fmtTime(tMin)}
                </text>
                <text x={W - PAD.r} y={H - 6} textAnchor="end" className="fill-white/35" fontSize={10}>
                    {fmtTime(tMax)}
                </text>
            </svg>
        </div>
    );
}
