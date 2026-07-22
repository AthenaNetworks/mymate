import { Handle, Position, type NodeProps } from '@xyflow/react';
import { MapTrifold, ArrowsOutSimple, X } from '@phosphor-icons/react';
import { setActiveMap } from '../../../lib/shellStore';

export type ChildMapNodeData = {
    mapId: number;
    name: string;
    deviceCount: number;
    onDetach?: () => void; // remove this map from the canvas (admin); the map itself stays
};

const handle = '!h-1.5 !w-1.5 !border-0 !bg-white/25';

// Attachment points on all four sides so a manual link binds to the side it was drawn
// from/to (mirrors DeviceNode). ids match MapLink::HANDLES on the backend.
const SIDES = [
    { pos: Position.Top, srcId: 's-top', tgtId: 't-top' },
    { pos: Position.Bottom, srcId: 's-bottom', tgtId: 't-bottom' },
    { pos: Position.Left, srcId: 's-left', tgtId: 't-left' },
    { pos: Position.Right, srcId: 's-right', tgtId: 't-right' },
] as const;

/**
 * A child map placed as a node on an overview map (GitHub #9). Double-click to drill into it;
 * links between these nodes are the manual, device-less topology of a top-level view.
 */
export function ChildMapNode({ data, selected }: NodeProps) {
    const d = data as unknown as ChildMapNodeData;

    return (
        <div
            onDoubleClick={() => setActiveMap(d.mapId)}
            title={`Double-click to open ${d.name}`}
            className={`group relative rounded-2xl bg-indigo-500/[0.08] p-1 ring-1 transition-all duration-300 ease-fluid ${
                selected
                    ? 'z-10 scale-[1.02] ring-2 ring-indigo-400/90 shadow-[0_0_0_5px_rgba(99,102,241,0.14),0_18px_55px_-12px_rgba(99,102,241,0.55)]'
                    : 'ring-indigo-400/20 shadow-[0_10px_34px_-10px_rgba(0,0,0,0.7)]'
            }`}
        >
            {SIDES.map(({ pos, srcId, tgtId }) => (
                <span key={pos}>
                    <Handle type="target" position={pos} id={tgtId} className={handle} />
                    <Handle type="source" position={pos} id={srcId} className={handle} />
                </span>
            ))}

            {/* Detach from the canvas (admin) - hover to reveal. The map itself is not deleted. */}
            {d.onDetach && (
                <button
                    type="button"
                    title="Remove this map from the overview"
                    onClick={(e) => { e.stopPropagation(); d.onDetach?.(); }}
                    className="nodrag absolute -right-1.5 -top-1.5 z-10 grid h-4 w-4 place-items-center rounded-full bg-rose-500/80 text-white opacity-0 ring-1 ring-rose-300/40 transition-opacity duration-200 group-hover:opacity-100 hover:!bg-rose-500"
                >
                    <X weight="bold" className="h-2.5 w-2.5" />
                </button>
            )}

            <div className="flex min-w-[10rem] items-center gap-2.5 rounded-[calc(1rem-0.25rem)] bg-[#0d0d11] px-3 py-2.5 ring-1 ring-white/10">
                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-400/25">
                    <MapTrifold weight="duotone" className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-white/90">{d.name}</div>
                    <div className="truncate text-[11px] text-white/40">
                        {d.deviceCount} {d.deviceCount === 1 ? 'device' : 'devices'}
                    </div>
                </div>
                <ArrowsOutSimple
                    weight="bold"
                    className="h-3.5 w-3.5 shrink-0 text-white/25 transition-colors group-hover:text-indigo-300"
                />
            </div>
        </div>
    );
}
