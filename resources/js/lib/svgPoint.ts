/**
 * A mouse/pointer position in an SVG's own (viewBox) coordinates.
 *
 * Uses the SVG's screen transform, so it's right whatever the SVG's on-screen size and however the
 * browser fits the viewBox into it - including the blank bands it leaves when the box's shape
 * differs from the drawing's (the default `preserveAspectRatio`). Measuring from an inner element's
 * bounding box, or scaling by the box's width, gets one of those wrong.
 *
 * `el` can be the <svg> itself or any element inside it. Null if the SVG isn't rendered (no
 * transform yet).
 */
export function svgPoint(el: Element, clientX: number, clientY: number): { x: number; y: number } | null {
    const svg = el instanceof SVGSVGElement ? el : (el as SVGElement).ownerSVGElement;
    const ctm = svg?.getScreenCTM();
    if (!ctm) return null;
    const p = new DOMPoint(clientX, clientY).matrixTransform(ctm.inverse());
    return { x: p.x, y: p.y };
}

/**
 * The other way: where a point in an SVG's viewBox coordinates is on screen, in client pixels -
 * eg to place an HTML tooltip exactly over something drawn in the SVG. Null if not rendered.
 */
export function svgToClient(el: Element, x: number, y: number): { x: number; y: number } | null {
    const svg = el instanceof SVGSVGElement ? el : (el as SVGElement).ownerSVGElement;
    const ctm = svg?.getScreenCTM();
    if (!ctm) return null;
    const p = new DOMPoint(x, y).matrixTransform(ctm);
    return { x: p.x, y: p.y };
}

/**
 * Where an x in an SVG's viewBox coordinates falls within the SVG's parent element, in px from the
 * parent's left edge - for an absolutely positioned HTML tooltip in that parent. Null if not
 * rendered.
 */
export function svgXInParent(svg: SVGSVGElement, x: number): number | null {
    const c = svgToClient(svg, x, 0);
    const box = svg.parentElement?.getBoundingClientRect();
    return c && box ? c.x - box.left : null;
}
