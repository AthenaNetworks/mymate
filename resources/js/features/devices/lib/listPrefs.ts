import type { DeviceListParams } from '../api/getDevices';
import type { Device } from '../../../types';

// The Devices tab's search, filters, sort and page, remembered per browser (localStorage) so
// opening a device and coming back - or reloading - lands on the same list. Clear resets them.

export type MapFilter = 'all' | 'placed' | 'unplaced' | `map:${number}`;

export interface DeviceListPrefs {
    query: string;
    status: 'all' | 'up' | 'down' | 'unknown';
    type: 'all' | Device['device_type'];
    monitored: 'all' | 'live' | 'paused';
    map: MapFilter;
    version: string;
    sort: NonNullable<DeviceListParams['sort']>;
    page: number;
}

export const DEFAULT_LIST_PREFS: DeviceListPrefs = {
    query: '',
    status: 'all',
    type: 'all',
    monitored: 'all',
    map: 'all',
    version: 'all',
    sort: 'name',
    page: 1,
};

const STORAGE_KEY = 'mymate.devices.list.v1';

const pick = <T extends string>(v: unknown, allowed: readonly T[], fallback: T): T =>
    typeof v === 'string' && (allowed as readonly string[]).includes(v) ? (v as T) : fallback;

/**
 * Whatever was saved, checked field by field: anything missing or unrecognised (an old or
 * hand-edited value) falls back to its default rather than breaking the list.
 */
export function loadListPrefs(sorts: readonly string[], types: readonly string[]): DeviceListPrefs {
    let raw: Record<string, unknown> = {};
    try {
        const s = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = s ? JSON.parse(s) : null;
        if (parsed && typeof parsed === 'object') raw = parsed as Record<string, unknown>;
    } catch {
        /* storage disabled or junk - defaults */
    }
    const d = DEFAULT_LIST_PREFS;
    const map = typeof raw.map === 'string' && /^(all|placed|unplaced|map:\d+)$/.test(raw.map) ? (raw.map as MapFilter) : d.map;
    const page = typeof raw.page === 'number' && Number.isInteger(raw.page) && raw.page >= 1 ? raw.page : d.page;
    return {
        query: typeof raw.query === 'string' ? raw.query.slice(0, 200) : d.query,
        status: pick(raw.status, ['all', 'up', 'down', 'unknown'] as const, d.status),
        type: pick(raw.type, ['all', ...types] as ('all' | Device['device_type'])[], d.type),
        monitored: pick(raw.monitored, ['all', 'live', 'paused'] as const, d.monitored),
        map,
        version: typeof raw.version === 'string' && raw.version !== '' ? raw.version.slice(0, 64) : d.version,
        sort: pick(raw.sort, sorts as NonNullable<DeviceListParams['sort']>[], d.sort),
        page,
    };
}

export function saveListPrefs(prefs: DeviceListPrefs): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch {
        /* storage disabled or over quota - ignore */
    }
}
