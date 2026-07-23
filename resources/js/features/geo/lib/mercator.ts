// Web-Mercator projection helpers for the geo map mode (GitHub #11). Device coordinates are
// projected into a flat "world pixel" space at a per-map base zoom; those become React Flow node
// positions, so the native nodes sit in the same space the tile background is drawn in. React
// Flow's own pan/zoom then moves nodes and tiles together.

const TILE = 256;

/** World size in pixels at a (possibly fractional) zoom. */
export function worldSize(z: number): number {
    return TILE * 2 ** z;
}

/** lat/lng -> world pixel at zoom z. */
export function project(lat: number, lng: number, z: number): { x: number; y: number } {
    const size = worldSize(z);
    const x = ((lng + 180) / 360) * size;
    const sin = Math.sin((lat * Math.PI) / 180);
    const clamped = Math.max(-0.9999, Math.min(0.9999, sin));
    const y = (0.5 - Math.log((1 + clamped) / (1 - clamped)) / (4 * Math.PI)) * size;
    return { x, y };
}

/** world pixel at zoom z -> lat/lng. */
export function unproject(x: number, y: number, z: number): { lat: number; lng: number } {
    const size = worldSize(z);
    const lng = (x / size) * 360 - 180;
    const n = Math.PI - (2 * Math.PI * y) / size;
    const lat = (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
    return { lat, lng };
}

/**
 * A base zoom for a set of coordinates so their projected bounding box is roughly `targetPx`
 * across - then React Flow's fitView lands near zoom 1, keeping the node cards their natural
 * size at the fitted view. A single point (zero span) falls back to a street-level default.
 */
export function computeBaseZoom(
    coords: Array<{ lat: number; lng: number }>,
    targetPx = 900,
    fallback = 15,
): number {
    if (coords.length < 2) return fallback;
    let latMin = 90, latMax = -90, lngMin = 180, lngMax = -180;
    for (const c of coords) {
        latMin = Math.min(latMin, c.lat); latMax = Math.max(latMax, c.lat);
        lngMin = Math.min(lngMin, c.lng); lngMax = Math.max(lngMax, c.lng);
    }
    // Span in normalised (0..1) Mercator units; use the larger axis.
    const lngFrac = (lngMax - lngMin) / 360;
    const yTop = 0.5 - Math.log((1 + Math.sin((latMax * Math.PI) / 180)) / (1 - Math.sin((latMax * Math.PI) / 180))) / (4 * Math.PI);
    const yBot = 0.5 - Math.log((1 + Math.sin((latMin * Math.PI) / 180)) / (1 - Math.sin((latMin * Math.PI) / 180))) / (4 * Math.PI);
    const latFrac = Math.abs(yBot - yTop);
    const frac = Math.max(lngFrac, latFrac, 1e-9);
    // targetPx = frac * worldSize(z) = frac * 256 * 2^z  ->  solve for z.
    const z = Math.log2(targetPx / (frac * TILE));
    return Math.max(0, Math.min(19, z));
}

export const TILE_SIZE = TILE;
