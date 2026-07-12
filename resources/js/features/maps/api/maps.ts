import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { MapDetail, NetworkMap } from '../../../types';

export const mapKeys = {
    all: ['maps'] as const,
    detail: (id: number) => ['maps', id] as const,
};

export function useMaps() {
    return useQuery({
        queryKey: mapKeys.all,
        queryFn: async (): Promise<NetworkMap[]> => {
            const { data } = await apiClient.get<{ data: NetworkMap[] }>('/maps');
            return data.data;
        },
    });
}

export function useMap(id: number | null) {
    return useQuery({
        queryKey: id === null ? (['maps', 'pending'] as const) : mapKeys.detail(id),
        queryFn: async (): Promise<MapDetail> => {
            const { data } = await apiClient.get<{ data: MapDetail }>(`/maps/${id}`);
            return data.data;
        },
        enabled: id !== null,
    });
}

export interface MapInput {
    id?: number;
    name: string;
    parent_map_id?: number | null;
}

export function useSaveMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: MapInput): Promise<NetworkMap> => {
            const { data } = id
                ? await apiClient.put<{ data: NetworkMap }>(`/maps/${id}`, input)
                : await apiClient.post<{ data: NetworkMap }>('/maps', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: mapKeys.all }),
    });
}

export function useDeleteMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/maps/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: mapKeys.all }),
    });
}

export function useSaveMapPosition() {
    return useMutation({
        mutationFn: async ({ mapId, deviceId, x, y }: { mapId: number; deviceId: number; x: number; y: number }): Promise<void> => {
            await apiClient.patch(`/maps/${mapId}/positions/${deviceId}`, { x, y });
        },
    });
}

/** Persist where an inter-map link's portal node sits on a map (drag to move). */
export function useSaveMapLinkPosition() {
    return useMutation({
        mutationFn: async ({ mapId, linkId, x, y }: { mapId: number; linkId: number; x: number; y: number }): Promise<void> => {
            await apiClient.patch(`/maps/${mapId}/links/${linkId}/position`, { x, y });
        },
    });
}

export function useAddDeviceToMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, deviceId }: { mapId: number; deviceId: number }): Promise<void> => {
            await apiClient.post(`/maps/${mapId}/devices`, { device_id: deviceId });
        },
        onSuccess: (_d, { mapId }) => {
            qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}

export function useRemoveDeviceFromMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, deviceId }: { mapId: number; deviceId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/devices/${deviceId}`);
        },
        onSuccess: (_d, { mapId }) => {
            qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}
