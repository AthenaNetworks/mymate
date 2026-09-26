import { useSyncExternalStore } from 'react';
import { NAVIGATE_EVENT, navigateTo, selectDevice, setActiveMap, setView } from '../../../lib/shellStore';

// The device page keeps its whole state in the URL (which device, port, tab and graph range),
// so a copied link reproduces the view. This is the small bit of routing it needs on top of
// the shell store: read the current path + query, and change it.

function subscribe(cb: () => void) {
    window.addEventListener('popstate', cb);
    window.addEventListener(NAVIGATE_EVENT, cb);
    return () => {
        window.removeEventListener('popstate', cb);
        window.removeEventListener(NAVIGATE_EVENT, cb);
    };
}

const snapshot = () => window.location.pathname + window.location.search;

/** pathname + search, re-rendering on Back/Forward and in-app navigation. */
export function useLocationHref(): string {
    return useSyncExternalStore(subscribe, snapshot);
}

export interface DeviceRoute {
    deviceId: number | null;
    portId: number | null;
    params: URLSearchParams;
}

export function parseDeviceRoute(href: string): DeviceRoute {
    const url = new URL(href, window.location.origin);
    const m = url.pathname.match(/^\/devices\/(\d+)(?:\/ports\/(\d+))?\/?$/);
    return {
        deviceId: m ? Number(m[1]) : null,
        portId: m && m[2] ? Number(m[2]) : null,
        params: url.searchParams,
    };
}

export function devicePath(deviceId: number, portId?: number | null): string {
    return portId ? `/devices/${deviceId}/ports/${portId}` : `/devices/${deviceId}`;
}

/** Open the full device page (optionally a port on it), keeping the graph range when asked. */
export function openDevicePage(deviceId: number, portId?: number | null, query?: URLSearchParams): void {
    const qs = query?.toString();
    navigateTo(devicePath(deviceId, portId) + (qs ? `?${qs}` : ''));
}

/**
 * Patch the query string of the current page. Range and tab tweaks replace the history entry
 * so Back leaves the page instead of stepping through every zoom.
 */
export function setQuery(patch: Record<string, string | null>, opts: { push?: boolean } = {}): void {
    const url = new URL(window.location.href);
    for (const [k, v] of Object.entries(patch)) {
        if (v === null || v === '') url.searchParams.delete(k);
        else url.searchParams.set(k, v);
    }
    navigateTo(url.pathname + url.search, { replace: !opts.push });
}

/** Jump to the map with this device selected, on a map it's actually placed on. */
export function showOnMap(deviceId: number, mapId: number | null): void {
    if (mapId !== null) setActiveMap(mapId);
    selectDevice(deviceId);
    setView('map');
}
