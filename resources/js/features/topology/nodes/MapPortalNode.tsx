import { Handle, Position, type NodeProps } from '@xyflow/react';
import { ArrowSquareOut } from '@phosphor-icons/react';
import { setActiveMap } from '../../../lib/shellStore';
import { formatRate } from '../../../lib/formatRate';
import { linkColor } from '../lib/linkColor';

export type MapPortalData = {
    deviceName: string;
    mapName: string;
    mapId: number | null;
    bps: number | null;
    util: number | null;
};

/** A connector to a device that lives on another map. Shows which device +
 *  map it leads to and the link\'s live throughput; click to jump to that map. Draggable
 *  (position persists). The inter-map link edge terminates on its target handle. */
export function MapPortalNode({ data }: NodeProps) {
    const d = data as unknown as MapPortalData;
    const rate = formatRate(d.bps, { compact: true });

    return (
        <button
            type="button"
            disabled={d.mapId === null}
            onClick={() => d.mapId !== null && setActiveMap(d.mapId)}
            className="flex items-center gap-2 rounded-xl bg-indigo-500/15 px-3 py-2 text-left ring-1 ring-indigo-400/30 transition-colors duration-200 hover:bg-indigo-500/25 disabled:cursor-default disabled:opacity-50"
            title={d.mapId !== null ? `Go to ${d.mapName}` : 'On no other map'}
        >
            <Handle type="target" position={Position.Top} className="!h-1.5 !w-1.5 !border-0 !bg-white/25" />
            <Handle type="source" position={Position.Bottom} className="!h-1.5 !w-1.5 !border-0 !bg-white/25" />

            {/* Load dot - coloured by util when a speed is known, else neutral. */}
            <span
                className="h-2 w-2 shrink-0 rounded-full"
                style={{ background: d.bps != null ? linkColor(d.util, false) : 'var(--link-unknown)' }}
            />
            <span className="min-w-0">
                <span className="flex items-center gap-1 text-xs font-semibold text-indigo-100">
                    <ArrowSquareOut weight="bold" className="h-3 w-3 shrink-0" />
                    <span className="max-w-[11rem] truncate">{d.deviceName}</span>
                </span>
                <span className="block truncate text-[10px] text-indigo-200/55">
                    {d.mapName}
                    {rate ? ` - ${rate}${d.util != null ? ` - ${d.util.toFixed(d.util < 10 ? 1 : 0)}%` : ''}` : ''}
                </span>
            </span>
        </button>
    );
}
