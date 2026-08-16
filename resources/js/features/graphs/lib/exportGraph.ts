import type { GraphData } from '../../../types';

// Export a custom graph as a PNG/SVG image or a CSV of its time series. The chart is a hand-rolled
// SVG, so image export clones that node into a standalone document; CSV is built straight from the
// resolved GraphData the chart draws.

const SVG_NS = 'http://www.w3.org/2000/svg';

function download(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/**
 * Clone the live chart SVG into a self-contained one for export: explicit pixel size, the theme
 * CSS variables it draws with resolved onto the root (so rgb(var(--graph-axis) / a) renders outside
 * the page), and a solid surface background so the ink reads. The interactive hover layer is HTML,
 * not SVG, so it's naturally excluded.
 */
function standaloneSvg(svg: SVGSVGElement): { node: SVGSVGElement; width: number; height: number } {
    const clone = svg.cloneNode(true) as SVGSVGElement;
    const vb = svg.viewBox.baseVal;
    const width = Math.round(vb?.width || svg.clientWidth || 760);
    const height = Math.round(vb?.height || svg.clientHeight || 260);

    clone.setAttribute('xmlns', SVG_NS);
    clone.setAttribute('width', String(width));
    clone.setAttribute('height', String(height));

    const root = getComputedStyle(document.documentElement);
    const varOr = (name: string, fallback: string) => root.getPropertyValue(name).trim() || fallback;
    clone.style.setProperty('--graph-axis', varOr('--graph-axis', '24 24 27'));
    clone.style.setProperty('--graph-ink', varOr('--graph-ink', '39 39 42'));

    const bg = document.createElementNS(SVG_NS, 'rect');
    bg.setAttribute('x', '0');
    bg.setAttribute('y', '0');
    bg.setAttribute('width', String(width));
    bg.setAttribute('height', String(height));
    bg.setAttribute('fill', varOr('--color-surface', '#0d0d11'));
    clone.insertBefore(bg, clone.firstChild);

    return { node: clone, width, height };
}

function serialize(node: SVGSVGElement): string {
    return `<?xml version="1.0" encoding="UTF-8"?>\n${new XMLSerializer().serializeToString(node)}`;
}

export function downloadGraphSvg(svg: SVGSVGElement, name: string): void {
    const { node } = standaloneSvg(svg);
    download(new Blob([serialize(node)], { type: 'image/svg+xml;charset=utf-8' }), `${name}.svg`);
}

/** Rasterise the standalone SVG onto a canvas at `scale`x for a crisp PNG. */
export function downloadGraphPng(svg: SVGSVGElement, name: string, scale = 2): Promise<void> {
    const { node, width, height } = standaloneSvg(svg);
    // Load the SVG through a data: URL, NOT a blob: one: the app's CSP is `img-src 'self' data:`
    // (no blob:), so a blob image would be blocked and the rasterisation would fail. data: is
    // allowed, and a self-contained SVG (no external refs) doesn't taint the canvas.
    const src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(serialize(node))}`;

    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = width * scale;
            canvas.height = height * scale;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                reject(new Error('no 2d context'));
                return;
            }
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((b) => {
                if (b) download(b, `${name}.png`);
                resolve();
            }, 'image/png');
        };
        img.onerror = () => reject(new Error('svg render failed'));
        img.src = src;
    });
}

/** RFC-4180 cell: quote when it contains a comma, quote or newline; double interior quotes. */
function csvCell(v: string): string {
    return /[",\r\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

/** A CSV of every bucket's timestamp + each series value (+ total), matching what the chart plots. */
export function downloadGraphCsv(data: GraphData, name: string): void {
    const cols = [...data.series.map((s) => s.label), ...(data.total ? ['Total'] : [])];
    const header = ['time (UTC)', ...cols].map(csvCell).join(',');

    const rows = data.buckets.map((bucket, i) => {
        const vals = [
            ...data.series.map((s) => s.values[i]),
            ...(data.total ? [data.total[i]] : []),
        ].map((v) => (v == null ? '' : String(v)));
        return [bucket, ...vals].map(csvCell).join(',');
    });

    download(new Blob([[header, ...rows].join('\r\n')], { type: 'text/csv;charset=utf-8' }), `${name}.csv`);
}

/** A filesystem-safe base name from the graph name + range, e.g. "Internet uplinks" -> "Internet_uplinks-24h". */
export function graphFileBase(graphName: string, range: string): string {
    const slug = graphName.trim().replace(/[^\w.-]+/g, '_').replace(/^_+|_+$/g, '');
    return `${slug || 'graph'}-${range}`;
}
