import { keepPreviousData, useInfiniteQuery, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { apiClient } from '../../../lib/apiClient';
import { BACKUP_IN_PROGRESS, UPGRADE_IN_PROGRESS, type Device, type DeviceMetricsFrame, type DeviceStatus, type DeviceType, type PollMethod } from '../../../types';

// There's no "whole fleet" query any more (GitHub #22). At 25k devices that one payload ran the
// server out of memory, so each screen asks for the slice it draws:
//   - a map canvas:       useMapDevices(mapId)       every device placed on that map
//   - the inspector:      useDevice(id)              one device
//   - the header:         useDeviceStats()           counts only
//   - lists + pickers:    useDeviceList(params)      a server-side page (search/filter/sort)
//   - name lookups:       useDevicesByIds(ids)       just those rows
// Live websocket updates are folded into all of them by patchCachedDevices below.

export type DeviceSort = 'name' | 'status' | 'mgmt_ip' | 'last_change' | 'vendor' | 'model';

export interface DeviceListParams {
    page?: number;
    per_page?: number;
    q?: string;
    status?: DeviceStatus;
    device_type?: DeviceType;
    poll_method?: PollMethod;
    monitored?: boolean;
    placed?: boolean;
    backup_enabled?: boolean;
    upgrading?: boolean;
    geo?: 'placed' | 'unplaced';
    map_id?: number;
    not_on_map?: number;
    parent_id?: number;
    not_under?: number;
    ids?: number[];
    sort?: DeviceSort | `-${DeviceSort}`;
    fields?: 'full' | 'summary';
}

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface DevicePage {
    data: Device[];
    meta: PageMeta;
}

export interface DeviceStats {
    up: number;
    down: number;
    unknown: number;
    paused: number;
    total: number;
}

// Query keys for the devices feature (co-located with the queries). The second segment says
// what shape the cached data is, which the live patcher relies on.
export const deviceKeys = {
    all: ['devices'] as const,
    lists: () => [...deviceKeys.all, 'list'] as const,
    list: (params: DeviceListParams) => [...deviceKeys.lists(), params] as const,
    detail: (id: number) => [...deviceKeys.all, 'detail', id] as const,
    map: (mapId: number) => [...deviceKeys.all, 'map', mapId] as const,
    ids: (ids: number[]) => [...deviceKeys.all, 'ids', ids] as const,
    stats: () => [...deviceKeys.all, 'stats'] as const,
};

// Laravel reads booleans as 1/0 and arrays as ids[]=; drop the unset keys.
function toQuery(params: DeviceListParams): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(params)) {
        if (v === undefined || v === null || v === '') continue;
        out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : v;
    }
    return out;
}

export async function fetchDevicePage(params: DeviceListParams): Promise<DevicePage> {
    const { data } = await apiClient.get<DevicePage>('/devices', { params: toQuery(params) });
    return { data: data.data, meta: data.meta };
}

const busy = (d: Device) =>
    (!!d.upgrade_status && UPGRADE_IN_PROGRESS.has(d.upgrade_status)) || (!!d.backup_status && BACKUP_IN_PROGRESS.has(d.backup_status));

// While any device on screen is mid-upgrade or mid-backup, poll so the spinner advances + resolves.
const pollWhileBusy = (rows: Device[] | undefined) => ((rows ?? []).some(busy) ? 3000 : false);

/** One server-side page of devices. Keeps the previous page on screen while the next loads. */
export function useDeviceList(params: DeviceListParams, options: { enabled?: boolean; refetchInterval?: number } = {}) {
    return useQuery({
        queryKey: deviceKeys.list(params),
        queryFn: () => fetchDevicePage(params),
        placeholderData: keepPreviousData,
        enabled: options.enabled ?? true,
        refetchInterval: (query) => pollWhileBusy(query.state.data?.data) || (options.refetchInterval ?? false),
    });
}

/** Pages appended as the operator scrolls / clicks "show more" (side lists, pickers). */
export function useInfiniteDeviceList(params: Omit<DeviceListParams, 'page'>, options: { enabled?: boolean } = {}) {
    return useInfiniteQuery({
        queryKey: [...deviceKeys.list(params), 'infinite'] as const,
        queryFn: ({ pageParam }) => fetchDevicePage({ ...params, page: pageParam }),
        initialPageParam: 1,
        getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
        enabled: options.enabled ?? true,
        placeholderData: keepPreviousData,
        refetchInterval: (query) => pollWhileBusy(query.state.data?.pages.flatMap((p) => p.data)),
    });
}

/** Every device placed on a map - the canvas's node set. */
export function useMapDevices(mapId: number | null) {
    return useQuery({
        queryKey: mapId === null ? ([...deviceKeys.all, 'map', 'none'] as const) : deviceKeys.map(mapId),
        queryFn: async (): Promise<Device[]> => {
            const { data } = await apiClient.get<{ data: Device[] }>(`/maps/${mapId}/devices`);
            return data.data;
        },
        enabled: mapId !== null,
        refetchInterval: (query) => pollWhileBusy(query.state.data),
    });
}

/** Look a device up in whatever device data is already cached (map, list pages, other details). */
export function findCachedDevice(qc: QueryClient, id: number): Device | undefined {
    for (const [key, data] of qc.getQueriesData<unknown>({ queryKey: deviceKeys.all })) {
        const found = rowsOf(key, data).find((d) => d.id === id);
        // A summary row is missing most fields; only full rows make a usable placeholder.
        if (found && 'status' in found && 'poll_method' in found && 'map_x' in found) return found;
    }
    return undefined;
}

/** One device (the inspector). Seeded from any cached row so a map click paints instantly. */
export function useDevice(id: number | null) {
    const qc = useQueryClient();
    return useQuery({
        queryKey: id === null ? ([...deviceKeys.all, 'detail', 'none'] as const) : deviceKeys.detail(id),
        queryFn: async (): Promise<Device> => {
            const { data } = await apiClient.get<{ data: Device }>(`/devices/${id}`);
            return data.data;
        },
        enabled: id !== null,
        placeholderData: () => (id === null ? undefined : findCachedDevice(qc, id)),
        retry: (count, err) => (err as { response?: { status?: number } })?.response?.status !== 404 && count < 2,
        refetchInterval: (query) => (query.state.data && busy(query.state.data) ? 3000 : false),
    });
}

const IDS_CHUNK = 200; // the list endpoint's per_page cap

/**
 * Just these devices (lean rows by default) - for turning ids into names. Chunked so a long id
 * list stays under the per-page cap.
 */
export function useDevicesByIds(ids: number[], fields: 'summary' | 'full' = 'summary') {
    const sorted = [...new Set(ids)].sort((a, b) => a - b);
    return useQuery({
        queryKey: [...deviceKeys.ids(sorted), fields] as const,
        queryFn: async (): Promise<Device[]> => {
            const chunks: number[][] = [];
            for (let i = 0; i < sorted.length; i += IDS_CHUNK) chunks.push(sorted.slice(i, i + IDS_CHUNK));
            const pages = await Promise.all(chunks.map((c) => fetchDevicePage({ ids: c, per_page: IDS_CHUNK, fields })));
            return pages.flatMap((p) => p.data);
        },
        enabled: sorted.length > 0,
        placeholderData: keepPreviousData,
        refetchInterval: (query) => (fields === 'full' ? pollWhileBusy(query.state.data) : false),
    });
}

/** Up/down/unknown (monitored) + paused counts for the header. Live flips adjust it in place. */
export function useDeviceStats() {
    return useQuery({
        queryKey: deviceKeys.stats(),
        queryFn: async (): Promise<DeviceStats> => {
            const { data } = await apiClient.get<{ data: DeviceStats }>('/devices/stats');
            return data.data;
        },
        // Live flips adjust it while a map/dashboard holds the socket open; this refetch covers
        // the other pages, adds/deletes and anything missed while disconnected. It's one tiny
        // GROUP BY, so 30 s is cheap.
        refetchInterval: 30_000,
    });
}

/** A value that only settles after `ms` of quiet - for search-as-you-type. */
export function useDebounced<T>(value: T, ms = 250): T {
    const [v, setV] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setV(value), ms);
        return () => clearTimeout(t);
    }, [value, ms]);
    return v;
}

// --- live cache patching ---------------------------------------------------------------------

type Shape = 'list' | 'detail' | 'map' | 'ids';

function shapeOf(key: readonly unknown[]): Shape | null {
    const s = key[1];
    return s === 'list' || s === 'detail' || s === 'map' || s === 'ids' ? s : null;
}

function rowsOf(key: readonly unknown[], data: unknown): Device[] {
    if (!data) return [];
    switch (shapeOf(key)) {
        case 'detail':
            return [data as Device];
        case 'map':
        case 'ids':
            return data as Device[];
        case 'list': {
            // A plain page, or an infinite query's { pages: [...] }.
            const d = data as { data?: Device[]; pages?: DevicePage[] };
            return d.pages ? d.pages.flatMap((p) => p.data) : (d.data ?? []);
        }
        default:
            return [];
    }
}

/**
 * Apply a live update to every cached copy of the affected devices, whatever query holds them
 * (map canvas, inspector, list pages, pickers). `patch` returns the changed fields for a device,
 * or null to leave it alone. Rows only get fields they already have, so a lean summary row
 * doesn't grow half a metrics block.
 */
export function patchCachedDevices(qc: QueryClient, patch: (id: number) => Partial<Device> | null) {
    const apply = (d: Device): Device => {
        const p = patch(d.id);
        if (!p) return d;
        const next = { ...d } as Record<string, unknown>;
        for (const [k, v] of Object.entries(p)) if (k in d) next[k] = v;
        return next as unknown as Device;
    };
    const mapRows = (rows: Device[]) => {
        let changed = false;
        const out = rows.map((d) => {
            const n = apply(d);
            if (n !== d) changed = true;
            return n;
        });
        return changed ? out : rows;
    };

    for (const [key, data] of qc.getQueriesData<unknown>({ queryKey: deviceKeys.all })) {
        if (!data) continue;
        const shape = shapeOf(key);
        let next: unknown = data;
        if (shape === 'detail') next = apply(data as Device);
        else if (shape === 'map' || shape === 'ids') next = mapRows(data as Device[]);
        else if (shape === 'list') {
            const d = data as { data?: Device[]; pages?: DevicePage[] };
            if (d.pages) {
                const pages = d.pages.map((p) => {
                    const rows = mapRows(p.data);
                    return rows === p.data ? p : { ...p, data: rows };
                });
                if (pages.some((p, i) => p !== d.pages![i])) next = { ...d, pages };
            } else if (d.data) {
                const rows = mapRows(d.data);
                if (rows !== d.data) next = { ...d, data: rows };
            }
        }
        if (next !== data) qc.setQueryData(key, next);
    }
}

/**
 * The device-page fields of a metrics frame as a device patch. Only what the frame has: a poll that
 * didn't read uptime or per-CPU leaves the cached values alone. uptime_at is stamped here, on
 * arrival, so the ticking uptime doesn't care whether this clock agrees with the server's.
 */
export function deviceExtras(f: DeviceMetricsFrame): Partial<Device> {
    const p: Partial<Device> = {};
    if (f.uptime_seconds !== undefined) {
        p.uptime_seconds = f.uptime_seconds;
        p.uptime_at = new Date().toISOString();
    }
    if (f.cpu_loads !== undefined) p.cpu_loads = f.cpu_loads;
    return p;
}
