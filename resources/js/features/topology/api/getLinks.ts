import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Link } from '../../../types';

// Query keys for the topology links (co-located with the query). Everything lives under `all`,
// so one invalidate (a link added, the socket reconnecting) refreshes every scoped copy.
export const linkKeys = {
    all: ['links'] as const,
    map: (mapId: number) => [...linkKeys.all, 'map', mapId] as const,
    device: (deviceId: number) => [...linkKeys.all, 'device', deviceId] as const,
};

/**
 * Links drawn on one map (both ends placed on it). Never the whole fleet: at 25k devices that
 * list with its interface rows is too big to ship to every open map (GitHub #22).
 */
export function useMapLinks(mapId: number | null) {
    return useQuery({
        queryKey: mapId === null ? ([...linkKeys.all, 'map', 'none'] as const) : linkKeys.map(mapId),
        queryFn: async (): Promise<Link[]> => {
            const { data } = await apiClient.get<{ data: Link[] }>('/links', { params: { map_id: mapId } });
            return data.data;
        },
        enabled: mapId !== null,
    });
}

/** Links touching one device, from any map (the inspector, the delete confirmation). */
export function useDeviceLinks(deviceId: number | null) {
    return useQuery({
        queryKey: deviceId === null ? ([...linkKeys.all, 'device', 'none'] as const) : linkKeys.device(deviceId),
        queryFn: async (): Promise<Link[]> => {
            const { data } = await apiClient.get<{ data: Link[] }>('/links', { params: { device_id: deviceId } });
            return data.data;
        },
        enabled: deviceId !== null,
    });
}
