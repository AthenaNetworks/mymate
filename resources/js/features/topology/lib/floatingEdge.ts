import { Position, type InternalNode, type Node } from '@xyflow/react';

/**
 * Floating-edge geometry (the React Flow "floating edges" recipe). Given two nodes,
 * returns the point on each node\'s border that faces the other node, plus which side
 * (Position) that point sits on - so a link attaches to whichever side faces its peer
 * instead of always the fixed top/bottom handles. Makes a mesh topology read cleanly.
 */

type Pt = { x: number; y: number };

function center(node: InternalNode<Node>): Pt {
    return {
        x: (node.internals.positionAbsolute.x ?? 0) + (node.measured?.width ?? 0) / 2,
        y: (node.internals.positionAbsolute.y ?? 0) + (node.measured?.height ?? 0) / 2,
    };
}

/** Intersection of the centre-to-centre line with `node`'s rectangle border. */
function intersection(node: InternalNode<Node>, target: InternalNode<Node>): Pt {
    const w = (node.measured?.width ?? 0) / 2;
    const h = (node.measured?.height ?? 0) / 2;
    const c = center(node);
    const t = center(target);

    if (w === 0 || h === 0) return c;

    const x2 = c.x;
    const y2 = c.y;
    const xx1 = (t.x - x2) / (2 * w) - (t.y - y2) / (2 * h);
    const yy1 = (t.x - x2) / (2 * w) + (t.y - y2) / (2 * h);
    const a = 1 / (Math.abs(xx1) + Math.abs(yy1) || 1);
    const xx3 = a * xx1;
    const yy3 = a * yy1;

    return { x: w * (xx3 + yy3) + x2, y: h * (-xx3 + yy3) + y2 };
}

/** Which side of `node` the intersection point lands on. */
function sideOf(node: InternalNode<Node>, p: Pt): Position {
    const px = Math.round((node.internals.positionAbsolute.x ?? 0));
    const py = Math.round((node.internals.positionAbsolute.y ?? 0));
    const w = node.measured?.width ?? 0;
    const x = Math.round(p.x);
    const y = Math.round(p.y);

    if (x <= px + 1) return Position.Left;
    if (x >= px + w - 1) return Position.Right;
    if (y <= py + 1) return Position.Top;
    return Position.Bottom;
}

export type FloatingParams = {
    sx: number;
    sy: number;
    tx: number;
    ty: number;
    sourcePos: Position;
    targetPos: Position;
};

export function getFloatingParams(
    source: InternalNode<Node>,
    target: InternalNode<Node>,
): FloatingParams {
    const sp = intersection(source, target);
    const tp = intersection(target, source);
    return {
        sx: sp.x,
        sy: sp.y,
        tx: tp.x,
        ty: tp.y,
        sourcePos: sideOf(source, sp),
        targetPos: sideOf(target, tp),
    };
}
