import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Device } from '../../../types';

// Data hooks for the full device page (GitHub #28). The page fetches its own single device
// rather than picking it out of the fleet list, so it works on any size of install and a
// deep link doesn't wait on every device loading first.

export const devicePageKeys = {
    all: ['device-page'] as const,
    device: (id: number) => ['device-page', id, 'device'] as const,
    summary: (id: number) => ['device-page', id, 'summary'] as const,
    catalog: (id: number) => ['device-page', id, 'catalog'] as const,
    history: (id: number, q: HistoryParams) => ['device-page', id, 'history', q] as const,
    billing: (id: number, q: BillingParams) => ['device-page', id, 'billing', q] as const,
    events: (id: number, page: number, types: string[]) => ['device-page', id, 'events', page, types] as const,
};

export function useDevicePageDevice(id: number) {
    return useQuery({
        queryKey: devicePageKeys.device(id),
        queryFn: async (): Promise<Device> => {
            const { data } = await apiClient.get<{ data: Device }>(`/devices/${id}`);
            return data.data;
        },
        refetchInterval: 30_000,
        retry: (count, err: unknown) => (err as { response?: { status?: number } })?.response?.status !== 404 && count < 2,
    });
}

export interface DeviceSummary {
    maps: { id: number; name: string }[];
    agent: { id: number; name: string; status: string | null } | null;
    interfaces: number;
    open_alerts: { id: number; status: string; policy_name: string | null; message: string | null; fired_at: string | null; acknowledged: boolean }[];
}

export function useDeviceSummary(id: number) {
    return useQuery({
        queryKey: devicePageKeys.summary(id),
        queryFn: async (): Promise<DeviceSummary> => {
            const { data } = await apiClient.get<{ data: DeviceSummary }>(`/devices/${id}/summary`);
            return data.data;
        },
        refetchInterval: 60_000,
    });
}

export interface CatalogMetric {
    metric: string;
    label: string;
    unit: string | null;
    group: string;
    aggs: string[];
}

export interface CatalogKey {
    key: string;
    label: string;
    unit?: string | null;
    description?: string | null;
}

export interface CatalogFamily {
    family: string;
    label: string;
    group: string;
    key_label: string | null;
    keyed: boolean;
    owner: string | null; // 'interfaces' | 'probes' when keys are the device's own rows
    metrics: CatalogMetric[];
    keys: CatalogKey[] | null;
}

export interface HistoryCatalog {
    device_id: number;
    families: CatalogFamily[];
    retention_days: { raw: number; '5m': number; '1h': number };
    max_days: number;
}

export function useHistoryCatalog(id: number) {
    return useQuery({
        queryKey: devicePageKeys.catalog(id),
        queryFn: async (): Promise<HistoryCatalog> => {
            const { data } = await apiClient.get<{ data: HistoryCatalog }>(`/devices/${id}/history/catalog`);
            return data.data;
        },
        staleTime: 5 * 60_000,
    });
}

export interface HistoryParams {
    family: string;
    metrics: string[];
    keys?: string[];
    from: number; // unix seconds
    to: number;
    points: number;
    aggregate?: boolean;
    p95?: boolean;
}

export interface SeriesStats {
    min: number | null;
    avg: number | null;
    max: number | null;
    last: number | null;
    peak: number | null;
    p95?: number | null;
}

export interface HistorySeries {
    key: string | null;
    metric: string;
    label: string;
    unit: string | null;
    avg: (number | null)[];
    max: (number | null)[] | null;
    min: (number | null)[] | null;
    stats: SeriesStats;
}

export interface HistoryResult {
    family: string;
    tier: 'raw' | '5m' | '1h';
    step: number;
    origin: string;
    count: number;
    from: string;
    to: string;
    aggregate: boolean;
    p95_resolution: '5m' | '1h' | null;
    series: HistorySeries[];
}

export async function fetchHistory(id: number, q: HistoryParams): Promise<HistoryResult> {
    const { data } = await apiClient.get<{ data: HistoryResult }>(`/devices/${id}/history`, {
        params: {
            family: q.family,
            'metrics[]': q.metrics,
            'keys[]': q.keys && q.keys.length ? q.keys : undefined,
            from: new Date(q.from * 1000).toISOString(),
            to: new Date(q.to * 1000).toISOString(),
            points: q.points,
            aggregate: q.aggregate ? 1 : undefined,
            p95: q.p95 ? 1 : undefined,
        },
    });
    return data.data;
}

/** One graph's data. Only fetched while `enabled` (the graph is on screen). */
export function useHistory(id: number, q: HistoryParams, enabled: boolean, live: boolean) {
    return useQuery({
        queryKey: devicePageKeys.history(id, q),
        queryFn: () => fetchHistory(id, q),
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
        refetchInterval: live && enabled ? 60_000 : false,
    });
}

export type BillingPeriod = 'this_month' | 'last_month' | 'custom';

export interface BillingParams {
    period: BillingPeriod;
    from?: number;
    to?: number;
    keys: string[];
    aggregate?: boolean;
}

export interface BillingFigures {
    p95_in: number | null;
    p95_out: number | null;
    p95_max: number | null;
    bytes_in: number | null;
    bytes_out: number | null;
    samples: number;
}

export interface BillingResult {
    period: BillingPeriod;
    label: string;
    from: string;
    to: string;
    resolution: '5m' | '1h';
    precise: boolean;
    step: number;
    expected_samples: number;
    ports: (BillingFigures & { key: string; label: string; description: string | null; speed_mbps: number | null })[];
    aggregate: BillingFigures | null;
}

export function useBilling(id: number, q: BillingParams, enabled = true) {
    return useQuery({
        queryKey: devicePageKeys.billing(id, q),
        queryFn: async (): Promise<BillingResult> => {
            const { data } = await apiClient.get<{ data: BillingResult }>(`/devices/${id}/billing`, {
                params: {
                    period: q.period,
                    from: q.from ? new Date(q.from * 1000).toISOString() : undefined,
                    to: q.to ? new Date(q.to * 1000).toISOString() : undefined,
                    'keys[]': q.keys.length ? q.keys : undefined,
                    aggregate: q.aggregate === undefined ? undefined : q.aggregate ? 1 : 0,
                },
            });
            return data.data;
        },
        enabled,
        placeholderData: keepPreviousData,
    });
}

export type DeviceEventType = 'outage' | 'alert' | 'backup' | 'upgrade' | 'reboot';

export interface DeviceEvent {
    id: string;
    type: DeviceEventType;
    kind: string;
    at: string;
    title: string;
    detail: string | null;
    commit?: string | null;
}

export interface DeviceEventsPage {
    data: DeviceEvent[];
    meta: { page: number; per_page: number; total: number; has_more: boolean };
}

export function useDeviceEvents(id: number, page: number, types: DeviceEventType[]) {
    return useQuery({
        queryKey: devicePageKeys.events(id, page, types),
        queryFn: async (): Promise<DeviceEventsPage> => {
            const { data } = await apiClient.get<DeviceEventsPage>(`/devices/${id}/events`, {
                params: { page, per_page: 50, 'types[]': types.length ? types : undefined },
            });
            return data;
        },
        placeholderData: keepPreviousData,
        refetchInterval: 60_000,
    });
}
