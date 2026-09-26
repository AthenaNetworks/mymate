import { useSyncExternalStore } from 'react';

// Shared hover time for every graph on the page: hovering one draws the crosshair (and the
// values under it) on all of them. Only the small crosshair overlay subscribes, so moving the
// mouse never re-renders the chart paths themselves.

let hoverAt: number | null = null; // epoch ms
const listeners = new Set<() => void>();

export function setHoverTime(t: number | null): void {
    if (hoverAt === t) return;
    hoverAt = t;
    for (const l of listeners) l();
}

function subscribe(cb: () => void) {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
}

export function useHoverTime(): number | null {
    return useSyncExternalStore(subscribe, () => hoverAt);
}
