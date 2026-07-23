import { useEffect, useRef, useState } from 'react';
import { useViewport } from '@xyflow/react';
import { TILE_SIZE } from '../lib/mercator';

/**
 * Map tiles drawn behind the React Flow nodes (GitHub #11). Node positions live in world-pixel
 * space at `baseZoom`, so we render the right slippy tiles for the current React Flow viewport and
 * apply the exact same transform - the map and the nodes pan/zoom in perfect lockstep. Purely a
 * backdrop: pointer-events off, so React Flow keeps handling all interaction.
 */
export function GeoTileLayer({ baseZoom, tileUrl }: { baseZoom: number; tileUrl: string }) {
    const { x, y, zoom } = useViewport();
    const ref = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ w: 0, h: 0 });

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const ro = new ResizeObserver(() => setSize({ w: el.clientWidth, h: el.clientHeight }));
        ro.observe(el);
        setSize({ w: el.clientWidth, h: el.clientHeight });
        return () => ro.disconnect();
    }, []);

    // Map zoom the viewport is currently showing, and the integer tile level nearest to it.
    const mapZoom = baseZoom + Math.log2(zoom);
    const tileZ = Math.max(0, Math.min(19, Math.round(mapZoom)));
    const n = 2 ** tileZ; // tiles per axis at this level
    // One tileZ tile is this many flow (baseZoom world) pixels wide.
    const flowPerTile = TILE_SIZE * 2 ** (baseZoom - tileZ);

    // Visible region in flow coordinates -> the tile index range it covers.
    const flowLeft = -x / zoom;
    const flowTop = -y / zoom;
    const flowRight = (size.w - x) / zoom;
    const flowBottom = (size.h - y) / zoom;
    const x0 = Math.max(0, Math.floor(flowLeft / flowPerTile));
    const x1 = Math.min(n - 1, Math.floor(flowRight / flowPerTile));
    const y0 = Math.max(0, Math.floor(flowTop / flowPerTile));
    const y1 = Math.min(n - 1, Math.floor(flowBottom / flowPerTile));

    const tiles: { key: string; url: string; left: number; top: number }[] = [];
    if (size.w > 0 && Number.isFinite(x0)) {
        for (let tx = x0; tx <= x1; tx++) {
            for (let ty = y0; ty <= y1; ty++) {
                const url = tileUrl
                    .replace('{s}', ['a', 'b', 'c'][(tx + ty) % 3])
                    .replace('{z}', String(tileZ))
                    .replace('{x}', String(tx))
                    .replace('{y}', String(ty));
                tiles.push({ key: `${tileZ}/${tx}/${ty}`, url, left: tx * flowPerTile, top: ty * flowPerTile });
            }
        }
    }

    return (
        <div ref={ref} className="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                className="absolute left-0 top-0"
                style={{ transform: `translate(${x}px, ${y}px) scale(${zoom})`, transformOrigin: '0 0' }}
            >
                {tiles.map((t) => (
                    <img
                        key={t.key}
                        src={t.url}
                        alt=""
                        draggable={false}
                        style={{ position: 'absolute', left: t.left, top: t.top, width: flowPerTile, height: flowPerTile, imageRendering: 'auto' }}
                    />
                ))}
            </div>
        </div>
    );
}
