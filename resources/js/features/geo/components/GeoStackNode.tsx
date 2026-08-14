import { Handle, Position, type NodeProps } from '@xyflow/react';
import { StackSimple } from '@phosphor-icons/react';
import type { DeviceStatus } from '../../../types';

export type GeoStackNodeData = {
    count: number;
    down: number; // how many are down
    names: string[];
    onToggle?: () => void;
};

const handle = '!h-1.5 !w-1.5 !border-0 !bg-white/25';
const SIDES = [
    { pos: Position.Top, s: 's-top', t: 't-top' },
    { pos: Position.Bottom, s: 's-bottom', t: 't-bottom' },
    { pos: Position.Left, s: 's-left', t: 't-left' },
    { pos: Position.Right, s: 's-right', t: 't-right' },
] as const;

/**
 * Several devices occupying the same spot on the geo map, collapsed into one node (GitHub #11).
 * Click to fan the members out; click a fanned card's site to collapse again. Carries handles so
 * links to any stacked device converge on it while collapsed.
 */
export function GeoStackNode({ data, selected }: NodeProps) {
    const d = data as unknown as GeoStackNodeData;
    const status: DeviceStatus = d.down > 0 ? 'down' : 'up';
    const ring = d.down > 0 ? 'ring-rose-500/70' : 'ring-emerald-400/40';

    return (
        <button
            type="button"
            onClick={(e) => { e.stopPropagation(); d.onToggle?.(); }}
            title={`${d.count} devices here:\n${d.names.slice(0, 12).join('\n')}${d.names.length > 12 ? '\n...' : ''}`}
            className={`relative grid place-items-center rounded-2xl bg-surface p-1 ring-1 transition-all duration-200 ${
                selected ? 'z-10 scale-[1.03] ring-2 ring-emerald-400/90' : ring
            }`}
        >
            {SIDES.map(({ pos, s, t }) => (
                <span key={pos}>
                    <Handle type="target" position={pos} id={t} className={handle} />
                    <Handle type="source" position={pos} id={s} className={handle} />
                </span>
            ))}
            <div className="flex min-w-[6rem] items-center gap-2 px-2.5 py-2">
                <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ring-1 ${d.down > 0 ? 'bg-rose-500/15 text-rose-300 ring-rose-400/25' : 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25'}`}>
                    <StackSimple weight="duotone" className="h-5 w-5" />
                </span>
                <div className="min-w-0 text-left">
                    <div className="text-sm font-semibold text-white/90">{d.count} devices</div>
                    <div className="text-[11px] text-white/40">{status === 'down' ? `${d.down} down` : 'same site'}</div>
                </div>
            </div>
            <span className="pointer-events-none absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full bg-white/90 px-1 text-[9px] font-bold text-surface">
                {d.count}
            </span>
        </button>
    );
}
