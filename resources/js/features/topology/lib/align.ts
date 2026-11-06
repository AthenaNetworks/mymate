/**
 * Align and distribute a selection of map cards, the way drawing tools do.
 *  - Align: every card lines up on one edge or centre line of the selection's bounding box.
 *  - Distribute: the two outermost cards stay put and the ones between move so the gaps between
 *    neighbours are equal (equal spacing, not equal centres - cards differ in size, and equal
 *    gaps is what reads as "even").
 * Cards differ in size (a device with face sensors is taller), so each one is placed by its own
 * measured width/height - "right" lines up the right edges, not the top-left corners.
 */
export type AlignKind = 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom' | 'hdistribute' | 'vdistribute';

/** Fewest cards each option needs to do anything: two to align, three to distribute. */
export const ALIGN_MIN: Record<AlignKind, number> = {
    left: 2, hcenter: 2, right: 2, top: 2, vmiddle: 2, bottom: 2, hdistribute: 3, vdistribute: 3,
};

export interface AlignBox {
    id: number;
    x: number;
    y: number;
    w: number;
    h: number;
}

export const ALIGN_LABEL: Record<AlignKind, string> = {
    left: 'Align left',
    hcenter: 'Align horizontal center',
    right: 'Align right',
    top: 'Align top',
    vmiddle: 'Align vertical middle',
    bottom: 'Align bottom',
    hdistribute: 'Distribute horizontally',
    vdistribute: 'Distribute vertically',
};

/** New top-left position per id. Only the aligned axis moves; the other is left as it was. */
export function alignBoxes(kind: AlignKind, boxes: AlignBox[]): Record<number, { x: number; y: number }> {
    const out: Record<number, { x: number; y: number }> = {};
    if (boxes.length === 0) return out;
    if (kind === 'hdistribute' || kind === 'vdistribute') return distribute(kind === 'hdistribute' ? 'x' : 'y', boxes);
    const minX = Math.min(...boxes.map((b) => b.x));
    const maxX = Math.max(...boxes.map((b) => b.x + b.w));
    const minY = Math.min(...boxes.map((b) => b.y));
    const maxY = Math.max(...boxes.map((b) => b.y + b.h));
    const midX = (minX + maxX) / 2;
    const midY = (minY + maxY) / 2;
    for (const b of boxes) {
        let { x, y } = b;
        switch (kind) {
            case 'left': x = minX; break;
            case 'hcenter': x = midX - b.w / 2; break;
            case 'right': x = maxX - b.w; break;
            case 'top': y = minY; break;
            case 'vmiddle': y = midY - b.h / 2; break;
            case 'bottom': y = maxY - b.h; break;
        }
        // Whole pixels, so a stored position doesn't carry float noise from the centring maths.
        out[b.id] = { x: Math.round(x), y: Math.round(y) };
    }
    return out;
}

/** Equal gaps along one axis, outermost cards fixed. Order is by leading edge (id breaks ties so a
 *  repeat run is stable). If the cards are wider than the span the gap goes negative and they
 *  overlap evenly - the same as drawing tools; Remove overlaps fixes that. */
function distribute(axis: 'x' | 'y', boxes: AlignBox[]): Record<number, { x: number; y: number }> {
    const out: Record<number, { x: number; y: number }> = {};
    const size = (b: AlignBox) => (axis === 'x' ? b.w : b.h);
    const sorted = [...boxes].sort((a, b) => a[axis] - b[axis] || a.id - b.id);
    if (sorted.length < 3) {
        for (const b of sorted) out[b.id] = { x: b.x, y: b.y };
        return out;
    }
    const first = sorted[0];
    const last = sorted[sorted.length - 1];
    // First and last never move; the inner cards share out what's left between them.
    const inner = sorted.slice(1, -1).reduce((sum, b) => sum + size(b), 0);
    const gap = (last[axis] - (first[axis] + size(first)) - inner) / (sorted.length - 1);
    let cursor = first[axis];
    for (const b of sorted) {
        const at = b === first || b === last ? b[axis] : cursor;
        out[b.id] = axis === 'x' ? { x: Math.round(at), y: b.y } : { x: b.x, y: Math.round(at) };
        cursor = at + size(b) + gap;
    }
    return out;
}
