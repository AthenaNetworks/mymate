import { useSyncExternalStore } from 'react';

/**
 * Short-lived Map tools state that must outlive the palette component but not a page reload, so it
 * is kept in memory (not in the persisted shell store, which writes localStorage on every change):
 *
 *  - the palette's search text. The palette unmounts whenever a device is selected (the sidebar
 *    shows that device instead), so plain component state reset the search every time you went
 *    back to Map tools.
 *  - which devices were just placed on the map, so their cards can flash once as feedback. Placing
 *    a device no longer selects it (that swapped the sidebar away from Map tools after every add).
 */

/** How long a placed device counts as "just placed". Covers the add round-trip + the refetch that
 * draws the card, plus the flash animation itself (see .animate-placed in app.css). */
const FLASH_MS = 2500;

let search = '';
let flashing: ReadonlySet<number> = new Set();
// Per-device token, so placing the same device again restarts its flash instead of an older
// timer cutting the new one short.
const flashToken = new Map<number, number>();
let nextToken = 0;
const listeners = new Set<() => void>();

function emit(): void {
    for (const l of listeners) l();
}

function subscribe(cb: () => void): () => void {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
}

export function setMapToolsSearch(q: string): void {
    if (q === search) return;
    search = q;
    emit();
}

export function useMapToolsSearch(): string {
    return useSyncExternalStore(subscribe, () => search);
}

/** Flash a device's card on the map once, e.g. right after it was placed from Map tools. */
export function flashPlacedDevice(deviceId: number): void {
    const token = ++nextToken;
    flashToken.set(deviceId, token);
    if (!flashing.has(deviceId)) {
        flashing = new Set(flashing).add(deviceId);
        emit();
    }
    window.setTimeout(() => {
        if (flashToken.get(deviceId) !== token) return; // placed again since - its own timer ends it
        flashToken.delete(deviceId);
        const next = new Set(flashing);
        next.delete(deviceId);
        flashing = next;
        emit();
    }, FLASH_MS);
}

export function useDevicePlacedFlash(deviceId: number): boolean {
    return useSyncExternalStore(subscribe, () => flashing.has(deviceId));
}
