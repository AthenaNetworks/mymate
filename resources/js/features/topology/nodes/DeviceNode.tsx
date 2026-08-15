import { memo } from 'react';
import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { DeviceStatus, DeviceType, TileMetric } from '../../../types';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceGlyph } from './DeviceGlyph';
import { linkColor } from '../lib/linkColor';
import { useDeviceTileMetric, setDeviceTileMetric } from '../../../lib/shellStore';

export type DeviceNodeData = {
    label: string;
    mgmt_ip: string;
    status: DeviceStatus;
    device_type: DeviceType;
    icon: string | null; // operator glyph override
    icon_color: string | null;
    vendor: string | null;
    model: string | null;
    util: number | null; // device's busiest interface utilisation % (live) - null when speed unknown
    load: number | null; // device's busiest interface throughput in bps (live) - LOAD fallback when util% is null
    cpu: number | null; // resource metrics (live) - null when the device doesn't report them
    mem: number | null;
    temp: number | null;
    rtt_ms: number | null; // ping latency (live) - the internet/upstream card shows this
    loss_pct: number | null;
    latency_good_ms: number | null; // <= good -> green, >= bad -> red, between -> amber
    latency_bad_ms: number | null;
};

// Fallback quality thresholds when a device has none set (typical broadband RTT feel).
const LATENCY_GOOD_DEFAULT = 30;
const LATENCY_BAD_DEFAULT = 150;

// Colour the latency reading by the device's quality band. Loss trumps latency - any
// sustained packet loss reads red. Null rtt (never pinged) stays neutral grey.
function latencyColor(rtt: number | null, loss: number | null, good: number, bad: number): string {
    if (loss !== null && loss >= 5) return '#f87171';
    if (rtt === null) return 'rgba(255,255,255,0.15)';
    // Tolerate good/bad set in either order.
    const lo = Math.min(good, bad);
    const hi = Math.max(good, bad);
    if (rtt <= lo) return '#34d399';
    if (rtt >= hi) return '#f87171';
    return '#fbbf24';
}

/** Compact bits-per-second for the LOAD tile fallback ("5.0M", "125M", "1.2G", "12k"). */
function compactBps(bps: number): string {
    if (bps >= 1e9) return `${(bps / 1e9).toFixed(1)}G`;
    if (bps >= 1e6) return `${(bps / 1e6).toFixed(bps < 1e7 ? 1 : 0)}M`;
    if (bps >= 1e3) return `${Math.round(bps / 1e3)}k`;
    return `${Math.round(bps)}`;
}

// The tile can show any of these; the label is the little chip, next cycles on click.
const METRICS: TileMetric[] = ['throughput', 'cpu', 'mem', 'temp'];
const METRIC_LABEL: Record<TileMetric, string> = { throughput: 'LOAD', cpu: 'CPU', mem: 'MEM', temp: 'TEMP' };

// Temperature isn't a percentage - colour by rough hot/warm/cool thresholds instead of
// the utilisation ramp, and scale the bar against ~90C.
function tempColor(c: number): string {
    if (c >= 70) return '#f87171';
    if (c >= 50) return '#fbbf24';
    return '#34d399';
}

const handle = '!h-1.5 !w-1.5 !border-0 !bg-white/25';

// Attachment points on all four sides. Edges are drawn with floating
// geometry (see UtilEdge), so a link connects to whichever side faces its peer - far
// cleaner than forcing every link out the bottom and into the top. The Top target +
// Bottom source are left id-less so existing/programmatic edges (which carry no handle
// id) still bind exactly as before; the rest are id\'d extra anchors + drag points.
const SIDES = [
    { pos: Position.Top, srcId: 's-top', tgtId: 't-top' },
    { pos: Position.Bottom, srcId: 's-bottom', tgtId: 't-bottom' },
    { pos: Position.Left, srcId: 's-left', tgtId: 't-left' },
    { pos: Position.Right, srcId: 's-right', tgtId: 't-right' },
] as const;

function SideHandles() {
    return (
        <>
            {SIDES.map(({ pos, srcId, tgtId }) => (
                <span key={pos}>
                    <Handle type="target" position={pos} id={tgtId} className={handle} />
                    <Handle type="source" position={pos} id={srcId} className={handle} />
                </span>
            ))}
        </>
    );
}

// Type drives a mono abbreviation badge (NET/RTR/SW/AP/SRV); distinct muted tints
// keep roles glanceable without relying on colour alone (the abbreviation pairs it).
const typeMeta: Record<DeviceType, { abbr: string; tint: string }> = {
    internet: { abbr: 'NET', tint: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    router: { abbr: 'RTR', tint: 'bg-sky-500/15 text-sky-300 ring-sky-400/25' },
    switch: { abbr: 'SW', tint: 'bg-violet-500/15 text-violet-300 ring-violet-400/25' },
    ap: { abbr: 'AP', tint: 'bg-fuchsia-500/15 text-fuchsia-300 ring-fuchsia-400/25' },
    server: { abbr: 'SRV', tint: 'bg-amber-500/15 text-amber-300 ring-amber-400/25' },
    unknown: { abbr: 'DEV', tint: 'bg-white/5 text-white/40 ring-white/10' },
};

// Memoised: on a big map the parent patches node data on every poll/util tick, and without this
// every device card re-renders each time (janking the canvas + eating clicks). With memo + the
// skip-unchanged patches in MapCanvas, only cards whose data actually changed re-render.
export const DeviceNode = memo(function DeviceNode({ id, data, selected }: NodeProps) {
    const d = data as unknown as DeviceNodeData;
    const meta = typeMeta[d.device_type] ?? typeMeta.unknown;
    const down = d.status === 'down';

    // The internet/upstream object isn't polled for load/cpu/mem - its health is latency.
    // Render a fixed LATENCY readout (rtt + loss) coloured by the device's quality band.
    const isInternet = d.device_type === 'internet';

    // Which resource this tile shows (per-device, persisted). Clicking the chip cycles it.
    const metric = useDeviceTileMetric(Number(id));
    const cycle = () => setDeviceTileMetric(Number(id), METRICS[(METRICS.indexOf(metric) + 1) % METRICS.length]);

    // Resolve the chosen metric to a bar width (0-100), a colour, and a value label.
    const raw = metric === 'throughput' ? d.util : metric === 'cpu' ? d.cpu : metric === 'mem' ? d.mem : d.temp;
    const isTemp = metric === 'temp';
    // LOAD as a % needs a known interface speed; RouterOS virtual ifaces (and any port with no
    // negotiated rate) have none, so util is null. Rather than a bare dash, fall back to the
    // busiest interface's absolute throughput (bps is always measured) with a neutral bar.
    const showBps = metric === 'throughput' && raw === null && d.load !== null;
    const barPct = raw === null ? (showBps ? 14 : 0) : isTemp ? Math.min(Math.max((raw / 90) * 100, 0), 100) : Math.min(Math.max(raw, 0), 100);
    const barColor = down
        ? 'var(--link-down)'
        : showBps
            ? 'rgba(255,255,255,0.35)'
            : raw === null
                ? 'rgba(255,255,255,0.15)'
                : isTemp
                    ? tempColor(raw)
                    : linkColor(raw, false);
    const valueLabel = showBps ? compactBps(d.load as number) : raw === null ? '-' : isTemp ? `${Math.round(raw)}°` : `${raw.toFixed(raw < 10 ? 1 : 0)}%`;

    // Internet card: latency readout instead of the load/cpu/mem tile.
    const latGood = d.latency_good_ms ?? LATENCY_GOOD_DEFAULT;
    const latBad = d.latency_bad_ms ?? LATENCY_BAD_DEFAULT;
    const latColor = down ? 'var(--link-down)' : latencyColor(d.rtt_ms, d.loss_pct, latGood, latBad);
    const latBarPct = d.rtt_ms === null ? 0 : Math.min((d.rtt_ms / Math.max(latBad, 1)) * 100, 100);
    const latValue = down ? 'down' : d.rtt_ms === null ? '-' : `${Math.round(d.rtt_ms)}ms`;
    const hasLoss = !down && d.loss_pct !== null && d.loss_pct > 0;

    return (
        // Double-bezel: outer shell (machined tray) + inner core (glass plate). No backdrop-blur
        // here - nodes live in the transforming canvas where blur would tank performance.
        // Selection dominates the visual hierarchy - a bright emerald ring-2 + an emerald halo
        // + a small lift + raised stacking, so the picked device is unmissable across a NOC
        // screen. Down devices otherwise pulse a rose glow (same `cardpulse` as the Dashboard);
        // never colour-alone (the status dot pairs it). Honours prefers-reduced-motion.
        <div
            className={`relative rounded-[1.25rem] bg-white/[0.04] p-1 ring-1 transition-all duration-300 ease-fluid ${
                selected
                    ? 'z-10 scale-[1.02] ring-2 ring-emerald-400/90 shadow-[0_0_0_5px_rgba(16,185,129,0.14),0_18px_55px_-12px_rgba(16,185,129,0.55)]'
                    : down
                        ? 'animate-cardpulse ring-rose-500/70 shadow-[0_10px_34px_-10px_rgba(0,0,0,0.7)]'
                        : 'ring-white/5 shadow-[0_10px_34px_-10px_rgba(0,0,0,0.7)]'
            }`}
        >
            {/* Selected: a soft emerald corner tab so selection reads even at a glance / zoomed out. */}
            {selected && (
                <span className="pointer-events-none absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_10px_2px_rgba(16,185,129,0.8)]" />
            )}
            <div className="min-w-[12.5rem] rounded-[calc(1.25rem-0.25rem)] bg-surface px-3 py-2.5 ring-1 ring-white/10 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]">
                <SideHandles />

                <div className="flex items-center gap-2.5">
                    <span
                        className={`grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-lg ring-1 ${meta.tint}`}
                        title={[d.vendor, d.model].filter(Boolean).join(' ') || meta.abbr}
                    >
                        <DeviceGlyph deviceId={Number(id)} vendor={d.vendor} model={d.model} type={d.device_type} icon={d.icon} iconColor={d.icon_color} className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold text-white/90">{d.label}</div>
                        <div className="truncate text-[11px] tabular-nums text-white/40">{d.mgmt_ip}</div>
                    </div>
                    <StatusDot status={d.status} className="mt-0.5 self-start" />
                </div>

                {isInternet ? (
                    /* Internet/upstream: a fixed LATENCY readout (rtt + optional loss badge),
                       coloured by the device's quality thresholds. No metric cycling. */
                    <div className="mt-2.5 flex items-center gap-2">
                        <span className="shrink-0 rounded bg-white/5 px-1.5 py-0.5 text-[9px] font-semibold tracking-wide text-white/45 ring-1 ring-white/10">
                            LATENCY
                        </span>
                        <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/10">
                            <div
                                className="h-full rounded-full transition-all duration-500 ease-fluid"
                                style={{ width: `${latBarPct}%`, background: latColor }}
                            />
                        </div>
                        {hasLoss && (
                            <span className="shrink-0 rounded bg-rose-500/15 px-1 text-[9px] font-semibold tabular-nums text-rose-300 ring-1 ring-rose-400/25">
                                {d.loss_pct! < 10 ? d.loss_pct!.toFixed(1) : Math.round(d.loss_pct!)}% loss
                            </span>
                        )}
                        <span className="w-11 shrink-0 text-right text-[10px] tabular-nums" style={{ color: latColor }}>
                            {latValue}
                        </span>
                    </div>
                ) : (
                    /* Chosen resource - clickable metric chip + bar + value. The chip carries
                       `nodrag` so clicking it cycles the metric instead of dragging the node. */
                    <div className="mt-2.5 flex items-center gap-2">
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); cycle(); }}
                            title="Click to change the metric shown"
                            className="nodrag shrink-0 rounded bg-white/5 px-1.5 py-0.5 text-[9px] font-semibold tracking-wide text-white/45 ring-1 ring-white/10 transition-colors hover:bg-white/10 hover:text-white/70"
                        >
                            {METRIC_LABEL[metric]}
                        </button>
                        <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/10">
                            <div
                                className="h-full rounded-full transition-all duration-500 ease-fluid"
                                style={{ width: `${barPct}%`, background: barColor }}
                            />
                        </div>
                        <span className="w-10 shrink-0 text-right text-[10px] tabular-nums text-white/45">
                            {valueLabel}
                        </span>
                    </div>
                )}

            </div>
        </div>
    );
});
