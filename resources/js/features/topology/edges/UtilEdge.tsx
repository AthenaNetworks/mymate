import {
    BaseEdge,
    EdgeLabelRenderer,
    getBezierPath,
    getStraightPath,
    useInternalNode,
    type EdgeProps,
} from '@xyflow/react';
import { X } from '@phosphor-icons/react';
import { linkColor, linkWidth } from '../lib/linkColor';
import { getFloatingParams } from '../lib/floatingEdge';
import { formatMbps } from '../../../lib/formatRate';
import { useEdgeStyle } from '../../../lib/shellStore';

export type UtilEdgeData = {
    util: number | null; // higher of in/out across both bound interfaces
    mbps: number | null; // derived load on the busier end (util% x speed)
    down: boolean; // either endpoint device is down
    onRemove?: () => void; // request deletion of this link (hover the label -> ✕)
    emphasized?: boolean; // touches the selected device - bring it forward
    dimmed?: boolean; // a device is selected but this link isn't its - push it back
};

function label(d: UtilEdgeData): string {
    if (d.down) return 'down';
    const rate = formatMbps(d.mbps, { compact: true }); // "6.1G", "730M", "12k"
    // Percentage only when a speed is known (util computable); otherwise show the rate
    // alone - never a % or load colour for a speedless link (spec).
    const pct = d.util !== null ? `${d.util.toFixed(d.util < 10 ? 1 : 0)}%` : null;
    return [rate, pct].filter(Boolean).join(' ') || '-';
}

export function UtilEdge({
    id,
    source,
    target,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    data,
    selected,
}: EdgeProps) {
    const d = (data ?? { util: null, mbps: null, down: false }) as UtilEdgeData;
    const edgeStyle = useEdgeStyle(); // curved (default) or straight

    // Floating geometry: attach to whichever sides of the two cards face each other
    // (handles live on all four sides) rather than the fixed top/bottom. Falls back to
    // the handle-derived props if a node isn\'t measured yet.
    const sourceNode = useInternalNode(source);
    const targetNode = useInternalNode(target);
    let sx = sourceX,
        sy = sourceY,
        tx = targetX,
        ty = targetY,
        sPos = sourcePosition,
        tPos = targetPosition;
    if (sourceNode?.measured?.width && targetNode?.measured?.width) {
        const p = getFloatingParams(sourceNode, targetNode);
        sx = p.sx;
        sy = p.sy;
        tx = p.tx;
        ty = p.ty;
        sPos = p.sourcePos;
        tPos = p.targetPos;
    }

    // Both helpers return [path, labelX, labelY]; only the geometry differs, so the
    // colour ramp, width, dash-flow, and label below are identical either way.
    const [path, labelX, labelY] =
        edgeStyle === 'straight'
            ? getStraightPath({ sourceX: sx, sourceY: sy, targetX: tx, targetY: ty })
            : getBezierPath({ sourceX: sx, sourceY: sy, targetX: tx, targetY: ty, sourcePosition: sPos, targetPosition: tPos });

    const color = linkColor(d.util, d.down);
    const width = (d.down ? 3 : linkWidth(d.util)) + (selected || d.emphasized ? 1.5 : 0);
    // Flow speed: the shimmer travels faster as utilisation climbs (idle ~ 1.4s, saturated ~ 0.4s).
    const flowDur = Math.max(0.4, 1.4 - (d.util ?? 0) / 100);
    // Selection focus: a selected device's links come forward, the rest fade back.
    const baseOpacity = d.down ? 0.5 : 0.9;
    const opacity = d.dimmed ? baseOpacity * 0.22 : baseOpacity;

    return (
        <>
            {/* The wire: one solid, substantial line coloured by load - this carries the body.
                Down = a dimmer, static dashed line so it never reads as a "busy" link. */}
            <BaseEdge
                id={id}
                path={path}
                style={{
                    stroke: color,
                    strokeWidth: width,
                    strokeLinecap: 'round',
                    opacity,
                    cursor: 'pointer', // click an edge to open its utilisation history
                    strokeDasharray: d.down ? '2 8' : undefined,
                    // colour glides rather than snaps as utilisation changes
                    transition: 'stroke 600ms var(--ease-fluid), stroke-width 250ms var(--ease-fluid), opacity 400ms var(--ease-fluid)',
                }}
            />
            {/* Flow accent (up only): a slim white shimmer gliding over the solid wire - shows
                the link is live + the throughput direction, without the chunky-dash "motorway".
                Period 12 divides the keyframe\'s -24 offset -> seamless loop. */}
            {!d.down && !d.dimmed && (
                <path
                    className="mm-edge-flow"
                    d={path}
                    fill="none"
                    stroke="rgba(255,255,255,0.80)"
                    strokeWidth={Math.max(1.5, width * 0.42)}
                    strokeLinecap="round"
                    strokeDasharray="4 8"
                    style={{
                        pointerEvents: 'none',
                        animation: `dashflow ${flowDur.toFixed(2)}s linear infinite`,
                        transition: 'stroke-width 250ms var(--ease-fluid)',
                    }}
                />
            )}
            <EdgeLabelRenderer>
                <div
                    style={{
                        transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        borderColor: color,
                        opacity: d.dimmed ? 0.3 : 1,
                        transition: 'opacity 400ms var(--ease-fluid)',
                    }}
                    className="group pointer-events-auto absolute flex items-center gap-1 rounded-full border bg-[#0d0d11]/90 px-2 py-0.5 text-[10px] font-medium tabular-nums text-white/85 shadow-[0_4px_14px_-4px_rgba(0,0,0,0.85)] ring-1 ring-white/10"
                >
                    <span>{label(d)}</span>
                    {/* Hover the label -> a ✕ to remove the link (confirm dialog in MapCanvas). */}
                    {d.onRemove && (
                        <button
                            type="button"
                            title="Remove this link"
                            onClick={(e) => {
                                e.stopPropagation();
                                d.onRemove?.();
                            }}
                            className="-mr-1 grid h-3.5 w-3.5 shrink-0 place-items-center rounded-full text-white/0 transition-colors duration-200 ease-fluid group-hover:text-white/45 hover:!bg-rose-500/20 hover:!text-rose-300"
                        >
                            <X weight="bold" className="h-2.5 w-2.5" />
                        </button>
                    )}
                </div>
            </EdgeLabelRenderer>
        </>
    );
}
