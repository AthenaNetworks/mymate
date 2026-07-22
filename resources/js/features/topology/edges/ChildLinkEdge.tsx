import {
    BaseEdge,
    EdgeLabelRenderer,
    getBezierPath,
    useInternalNode,
    type EdgeProps,
} from '@xyflow/react';
import { getFloatingParams } from '../lib/floatingEdge';

export type ChildLinkEdgeData = {
    count: number;
};

/**
 * A read-only, aggregated edge between two child-map nodes on an overview (GitHub #9) - shows
 * how many real device links cross between the two sub-maps. One edge per pair (never a tangle
 * of overlapping lines); the count distinguishes multiplicity.
 */
export function ChildLinkEdge({ id, source, target, sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, data }: EdgeProps) {
    const d = (data ?? { count: 0 }) as ChildLinkEdgeData;

    const sourceNode = useInternalNode(source);
    const targetNode = useInternalNode(target);
    let sx = sourceX, sy = sourceY, tx = targetX, ty = targetY, sPos = sourcePosition, tPos = targetPosition;
    if (sourceNode?.measured?.width && targetNode?.measured?.width) {
        const p = getFloatingParams(sourceNode, targetNode);
        sx = p.sx; sy = p.sy; tx = p.tx; ty = p.ty; sPos = p.sourcePos; tPos = p.targetPos;
    }

    const [path, labelX, labelY] = getBezierPath({ sourceX: sx, sourceY: sy, targetX: tx, targetY: ty, sourcePosition: sPos, targetPosition: tPos });

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                style={{ stroke: 'rgba(148,163,184,0.55)', strokeWidth: 1.5, strokeDasharray: '2 5', strokeLinecap: 'round' }}
            />
            <EdgeLabelRenderer>
                <div
                    style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
                    className="pointer-events-none absolute rounded-full bg-[#0d0d11]/85 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-white/55 ring-1 ring-white/10"
                >
                    {d.count} {d.count === 1 ? 'link' : 'links'}
                </div>
            </EdgeLabelRenderer>
        </>
    );
}
