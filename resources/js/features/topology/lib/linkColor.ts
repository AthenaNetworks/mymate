// The ONE place the link-colour ramp math lives (single source of truth). util%
// is computed server-side and broadcast; here it becomes an HSL colour. Down is
// kept separate so it never reads as "busy".

// Curve: 1 = linear; >1 makes the colour change subtly at low load and sharply near
// saturation. Tunable in one place.
const RAMP_CURVE = 1;

/**
 * @param util percentage (0-100+), or null when unknown
 * @param down true if either endpoint device is down -> distinct grey, never red
 */
export function linkColor(util: number | null, down: boolean): string {
    if (down) {
        return 'var(--link-down)';
    }
    if (util === null) {
        // Throughput may be flowing, but with no known speed we can\'t express load -
        // use a neutral colour rather than implying "idle/green" (spec).
        return 'var(--link-unknown)';
    }

    const ratio = Math.min(Math.max(util, 0), 100) / 100;
    const curved = RAMP_CURVE === 1 ? ratio : ratio ** RAMP_CURVE;
    const hue = 120 - 120 * curved; // 120 deg green -> 60 deg yellow -> 0 deg red

    return `hsl(${hue}, 70%, 45%)`;
}

/** Stroke width scales subtly with load (idle links stay slim, busy links thicken).
 *  Kept deliberately slim so links read as fine wires, not a motorway - load is also
 *  encoded by colour + the % label, so width can stay subtle (never colour-alone). */
export function linkWidth(util: number | null): number {
    const ratio = Math.min(Math.max(util ?? 0, 0), 100) / 100;

    return 3.5 + ratio * 5; // 3.5px idle -> 8.5px saturated
}
