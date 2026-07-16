import {
    BaseEdge,
    EdgeLabelRenderer,
    getBezierPath,
    getStraightPath,
    useInternalNode,
    type EdgeProps,
} from '@xyflow/react';
import { X } from '@phosphor-icons/react';
import { getFloatingParams } from '../lib/floatingEdge';
import { useEdgeStyle } from '../../../lib/shellStore';
import { MEDIA_META } from '../lib/mediaType';
import type { LinkMediaType } from '../../../types';

export type MapLinkEdgeData = {
    mediaType: LinkMediaType | null;
    label: string | null;
    onRemove?: () => void;
    selected?: boolean;
};

/**
 * A manual, device-less link between two child-map nodes (GitHub #9). No live load - it's
 * coloured entirely by its physical medium (fibre/ethernet/wireless/other) and shows an
 * optional label. Floats to the facing sides unless the operator drew it to specific handles.
 */
export function MapLinkEdge({
    id,
    source,
    target,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    sourceHandleId,
    targetHandleId,
    data,
    selected,
}: EdgeProps) {
    const d = (data ?? { mediaType: null, label: null }) as MapLinkEdgeData;
    const edgeStyle = useEdgeStyle();
    const meta = d.mediaType ? MEDIA_META[d.mediaType] : null;
    const color = meta?.color ?? '#64748b';

    const sourceNode = useInternalNode(source);
    const targetNode = useInternalNode(target);
    let sx = sourceX,
        sy = sourceY,
        tx = targetX,
        ty = targetY,
        sPos = sourcePosition,
        tPos = targetPosition;
    const pinned = Boolean(sourceHandleId) || Boolean(targetHandleId);
    if (!pinned && sourceNode?.measured?.width && targetNode?.measured?.width) {
        const p = getFloatingParams(sourceNode, targetNode);
        sx = p.sx;
        sy = p.sy;
        tx = p.tx;
        ty = p.ty;
        sPos = p.sourcePos;
        tPos = p.targetPos;
    }

    const [path, labelX, labelY] =
        edgeStyle === 'straight'
            ? getStraightPath({ sourceX: sx, sourceY: sy, targetX: tx, targetY: ty })
            : getBezierPath({ sourceX: sx, sourceY: sy, targetX: tx, targetY: ty, sourcePosition: sPos, targetPosition: tPos });

    const width = 2.5 + (selected ? 1.5 : 0);
    const text = d.label ?? meta?.label ?? '';

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                style={{
                    stroke: color,
                    strokeWidth: width,
                    strokeLinecap: 'round',
                    strokeDasharray: meta?.dash,
                    opacity: 0.9,
                    cursor: 'pointer',
                    transition: 'stroke 400ms var(--ease-fluid), stroke-width 250ms var(--ease-fluid)',
                }}
            />
            {text && (
                <EdgeLabelRenderer>
                    <div
                        style={{
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                            borderColor: color,
                        }}
                        className="group pointer-events-auto absolute flex items-center gap-1 rounded-full border bg-[#0d0d11]/90 px-2 py-0.5 text-[10px] font-medium text-white/85 shadow-[0_4px_14px_-4px_rgba(0,0,0,0.85)] ring-1 ring-white/10"
                    >
                        <span>{text}</span>
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
            )}
        </>
    );
}
