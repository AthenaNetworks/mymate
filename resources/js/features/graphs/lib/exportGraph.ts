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
    // the tick labels inherit their font from the page, which a detached svg doesn't have
    clone.style.fontFamily = getComputedStyle(svg).fontFamily || 'sans-serif';

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

/**
 * A legend table to draw under the chart in the PNG, the same numbers the page shows under it.
 * Tone picks the ink per column: 'muted' for the secondary columns, 'accent' for the 95th.
 */
export interface PngLegend {
    columns: { label: string; tone?: 'muted' | 'accent' }[];
    rows: { label: string; color: string; cells: string[] }[];
}

export interface PngExtras {
    title?: string;
    subtitle?: string;
    legend?: PngLegend | null;
}

const PAD = 16;
const MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

type SpacedContext = CanvasRenderingContext2D & { letterSpacing?: string };

/** Cut a label down to fit, with a trailing "..." when it had to. */
function fit(ctx: CanvasRenderingContext2D, text: string, max: number): string {
    if (ctx.measureText(text).width <= max) return text;
    let t = text;
    while (t.length > 1 && ctx.measureText(`${t}...`).width > max) t = t.slice(0, -1);
    return `${t}...`;
}

/**
 * Lay out and paint the whole image: title, the rasterised chart, then the legend table drawn
 * straight onto the canvas (cheaper and crisper than trying to screenshot the html table).
 * Everything is worked out in css pixels and the context is scaled once, so 2x just works.
 */
function compose(img: HTMLImageElement, width: number, height: number, scale: number, extras: PngExtras, font: string): HTMLCanvasElement {
    const root = getComputedStyle(document.documentElement);
    const ink = root.getPropertyValue('--graph-axis').trim() || '255 255 255';
    const tone = (a: number) => `rgb(${ink} / ${a})`;
    const bg = root.getPropertyValue('--color-surface').trim() || '#0d0d11';
    const legend = extras.legend && extras.legend.rows.length > 0 ? extras.legend : null;

    // legend geometry: numeric columns sized to their widest cell, the label column takes the rest
    const rowH = 18;
    let colW: number[] = [];
    let labelW = 0;
    const measure = document.createElement('canvas').getContext('2d');
    if (legend && measure) {
        measure.font = `11px ${MONO}`;
        colW = legend.columns.map((c, i) => {
            const widest = Math.max(measure.measureText(c.label).width + 12, ...legend.rows.map((r) => measure.measureText(r.cells[i] ?? '').width));
            return Math.max(52, Math.ceil(widest) + 16);
        });
        measure.font = `11px ${font}`;
        labelW = Math.min(260, Math.max(120, ...legend.rows.map((r) => Math.ceil(measure.measureText(r.label).width) + 36)));
    }
    const legendW = legend ? labelW + colW.reduce((a, b) => a + b, 0) : 0;

    const innerW = Math.max(width, legendW);
    const titleH = extras.title ? (extras.subtitle ? 42 : 26) : 0;
    const legendH = legend ? 8 + rowH * (legend.rows.length + 1) : 0;
    const W = innerW + PAD * 2;
    const H = PAD + titleH + height + legendH + PAD;

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(W * scale);
    canvas.height = Math.round(H * scale);
    const ctx = canvas.getContext('2d') as SpacedContext | null;
    if (!ctx) throw new Error('no 2d context');
    ctx.scale(scale, scale);

    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H);

    let y = PAD;
    if (extras.title) {
        ctx.textBaseline = 'top';
        ctx.fillStyle = tone(0.88);
        ctx.font = `600 14px ${font}`;
        ctx.fillText(fit(ctx, extras.title, innerW), PAD, y);
        if (extras.subtitle) {
            ctx.fillStyle = tone(0.45);
            ctx.font = `11px ${font}`;
            ctx.fillText(fit(ctx, extras.subtitle, innerW), PAD, y + 21);
        }
        y += titleH;
    }

    ctx.drawImage(img, PAD, y, width, height);
    y += height;

    if (legend) {
        y += 8;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'right';
        ctx.font = `500 10px ${font}`;
        ctx.fillStyle = tone(0.35);
        if ('letterSpacing' in ctx) ctx.letterSpacing = '1.2px';
        let x = PAD + labelW;
        legend.columns.forEach((c, i) => {
            x += colW[i];
            ctx.fillText(c.label.toUpperCase(), x - 8, y + rowH / 2);
        });
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
        y += rowH;

        for (const r of legend.rows) {
            const mid = y + rowH / 2;
            ctx.fillStyle = r.color;
            ctx.fillRect(PAD + 8, mid - 1, 12, 2);
            ctx.textAlign = 'left';
            ctx.font = `11px ${font}`;
            ctx.fillStyle = tone(0.72);
            ctx.fillText(fit(ctx, r.label, labelW - 36), PAD + 26, mid);

            ctx.textAlign = 'right';
            ctx.font = `11px ${MONO}`;
            x = PAD + labelW;
            legend.columns.forEach((c, i) => {
                x += colW[i];
                ctx.fillStyle = c.tone === 'accent' ? 'rgb(252 211 77 / 0.9)' : c.tone === 'muted' ? tone(0.45) : tone(0.72);
                ctx.fillText(r.cells[i] ?? '', x - 8, mid);
            });
            y += rowH;
        }
    }

    return canvas;
}

/**
 * Rasterise the standalone SVG onto a canvas at `scale`x for a crisp PNG. With extras it also
 * gets a title and the stats legend drawn underneath, so the image stands on its own.
 */
export function downloadGraphPng(svg: SVGSVGElement, name: string, scale = 2, extras: PngExtras = {}): Promise<void> {
    const { node, width, height } = standaloneSvg(svg);
    const font = getComputedStyle(svg).fontFamily || 'sans-serif';
    // Load the SVG through a data: URL, NOT a blob: one: the app's CSP is `img-src 'self' data:`
    // (no blob:), so a blob image would be blocked and the rasterisation would fail. data: is
    // allowed, and a self-contained SVG (no external refs) doesn't taint the canvas.
    const src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(serialize(node))}`;

    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            let canvas: HTMLCanvasElement;
            try {
                canvas = compose(img, width, height, scale, extras, font);
            } catch (e) {
                reject(e);
                return;
            }
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
