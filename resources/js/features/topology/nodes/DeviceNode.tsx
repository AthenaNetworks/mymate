import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { DeviceStatus, DeviceType, TileMetric } from '../../../types';
import { StatusDot } from '../../../components/StatusDot';
import { linkColor } from '../lib/linkColor';
import { useDeviceTileMetric, setDeviceTileMetric } from '../../../lib/shellStore';

export type DeviceNodeData = {
    label: string;
    mgmt_ip: string;
    status: DeviceStatus;
    device_type: DeviceType;
    util: number | null; // device's busiest interface utilisation (live)
    cpu: number | null; // resource metrics (live) - null when the device doesn't report them
    mem: number | null;
    temp: number | null;
};

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
    { pos: Position.Top, srcId: 's-top', tgtId: null },
    { pos: Position.Bottom, srcId: null, tgtId: 't-bottom' },
    { pos: Position.Left, srcId: 's-left', tgtId: 't-left' },
    { pos: Position.Right, srcId: 's-right', tgtId: 't-right' },
] as const;

function SideHandles() {
    return (
        <>
            {SIDES.map(({ pos, srcId, tgtId }) => (
                <span key={pos}>
                    <Handle type="target" position={pos} id={tgtId ?? undefined} className={handle} />
                    <Handle type="source" position={pos} id={srcId ?? undefined} className={handle} />
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

export function DeviceNode({ id, data, selected }: NodeProps) {
    const d = data as unknown as DeviceNodeData;
    const meta = typeMeta[d.device_type] ?? typeMeta.unknown;
    const down = d.status === 'down';

    // Which resource this tile shows (per-device, persisted). Clicking the chip cycles it.
    const metric = useDeviceTileMetric(Number(id));
    const cycle = () => setDeviceTileMetric(Number(id), METRICS[(METRICS.indexOf(metric) + 1) % METRICS.length]);

    // Resolve the chosen metric to a bar width (0-100), a colour, and a value label.
    const raw = metric === 'throughput' ? d.util : metric === 'cpu' ? d.cpu : metric === 'mem' ? d.mem : d.temp;
    const isTemp = metric === 'temp';
    const barPct = raw === null ? 0 : isTemp ? Math.min(Math.max((raw / 90) * 100, 0), 100) : Math.min(Math.max(raw, 0), 100);
    const barColor = down ? 'var(--link-down)' : raw === null ? 'rgba(255,255,255,0.15)' : isTemp ? tempColor(raw) : linkColor(raw, false);
    const valueLabel = raw === null ? '-' : isTemp ? `${Math.round(raw)}°` : `${raw.toFixed(raw < 10 ? 1 : 0)}%`;

    return (
        // Double-bezel: outer shell (machined tray) + inner core (glass plate). No backdrop-blur
        // here - nodes live in the transforming canvas where blur would tank performance.
        // Down devices pulse a rose glow (same `cardpulse` as the Dashboard) so an outage is
        // unmissable across a NOC room - paired with the rose ring + the status dot (never
        // colour-alone); honours prefers-reduced-motion.
        <div
            className={`rounded-[1.25rem] bg-white/[0.04] p-1 ring-1 transition-all duration-300 ease-fluid ${
                down
                    ? 'animate-cardpulse ring-rose-500/70'
                    : `shadow-[0_10px_34px_-10px_rgba(0,0,0,0.7)] ${selected ? 'ring-emerald-400/50' : 'ring-white/5'}`
            }`}
        >
            <div className="min-w-[12.5rem] rounded-[calc(1.25rem-0.25rem)] bg-[#0d0d11] px-3 py-2.5 ring-1 ring-white/10 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]">
                <SideHandles />

                <div className="flex items-center gap-2.5">
                    <span
                        className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg font-mono text-[10px] font-semibold tracking-tight ring-1 ${meta.tint}`}
                    >
                        {meta.abbr}
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold text-white/90">{d.label}</div>
                        <div className="truncate text-[11px] tabular-nums text-white/40">{d.mgmt_ip}</div>
                    </div>
                    <StatusDot status={d.status} className="mt-0.5 self-start" />
                </div>

                {/* Chosen resource - clickable metric chip + bar + value. The chip carries
                    `nodrag` so clicking it cycles the metric instead of dragging the node. */}
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
                    <span className="w-8 shrink-0 text-right text-[10px] tabular-nums text-white/45">
                        {valueLabel}
                    </span>
                </div>

            </div>
        </div>
    );
}
