import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { LinkMediaType, MapDetail, MapLink, MapNote, NetworkMap } from '../../../types';

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
    leaflet_enabled?: boolean;
    ping_interval?: number | null;
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

// --- Child-map nodes + manual links (GitHub #9) ---------------------------

/** Place an existing map as a node on this canvas (nests it as a child). */
export function useAddChildMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, childMapId, x, y }: { mapId: number; childMapId: number; x?: number; y?: number }): Promise<void> => {
            await apiClient.post(`/maps/${mapId}/child-maps`, { child_map_id: childMapId, x, y });
        },
        onSuccess: (_d, { mapId }) => {
            qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}

/** Move a child-map node on the canvas (drag). Not invalidated - the node already moved. */
export function useSaveChildMapPosition() {
    return useMutation({
        mutationFn: async ({ mapId, childMapId, x, y }: { mapId: number; childMapId: number; x: number; y: number }): Promise<void> => {
            await apiClient.patch(`/maps/${mapId}/child-maps/${childMapId}/position`, { x, y });
        },
    });
}

/** Detach a child-map node from this canvas (the map itself is untouched). */
export function useRemoveChildMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, childMapId }: { mapId: number; childMapId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/child-maps/${childMapId}`);
        },
        onSuccess: (_d, { mapId }) => {
            qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}

export interface CreateMapLinkInput {
    mapId: number;
    a_map_id: number;
    b_map_id: number;
    a_handle?: string | null;
    b_handle?: string | null;
    media_type?: LinkMediaType | null;
}

/** Draw a manual link between two child-map nodes on this canvas. */
export function useCreateMapLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, ...body }: CreateMapLinkInput): Promise<MapLink> => {
            const { data } = await apiClient.post<{ data: MapLink }>(`/maps/${mapId}/map-links`, body);
            return data.data;
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

/** Update a manual link's medium / label. */
export function useUpdateMapLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, mapLinkId, media_type, label }: { mapId: number; mapLinkId: number; media_type?: LinkMediaType | null; label?: string | null }): Promise<MapLink> => {
            const { data } = await apiClient.patch<{ data: MapLink }>(`/maps/${mapId}/map-links/${mapLinkId}`, { media_type, label });
            return data.data;
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

/** Delete a manual link. */
export function useDeleteMapLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, mapLinkId }: { mapId: number; mapLinkId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/map-links/${mapLinkId}`);
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

// --- Export / import a map layout (GitHub #11) ----------------------------

/** Fetch a map's export JSON and trigger a browser download. */
export function useExportMap() {
    return useMutation({
        mutationFn: async ({ mapId, name }: { mapId: number; name: string }): Promise<void> => {
            const { data } = await apiClient.get(`/maps/${mapId}/export`);
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${name.replace(/[^a-z0-9-_]+/gi, '-').toLowerCase() || 'map'}.json`;
            a.click();
            URL.revokeObjectURL(url);
        },
    });
}

/** Import a previously-exported map layout; returns the new map's id. */
export function useImportMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: unknown): Promise<{ id: number; name: string }> => {
            const { data } = await apiClient.post<{ data: { id: number; name: string } }>('/maps/import', payload);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: mapKeys.all }),
    });
}

// --- Free-text map notes (GitHub #11) -------------------------------------

/** Add a free-text note to a map. */
export function useCreateMapNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, text, x, y }: { mapId: number; text: string; x?: number; y?: number }): Promise<MapNote> => {
            const { data } = await apiClient.post<{ data: MapNote }>(`/maps/${mapId}/notes`, { text, x, y });
            return data.data;
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

/** Update a note's text / position / colour. Position saves aren't invalidated (already moved). */
export function useUpdateMapNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, noteId, text, x, y, color }: { mapId: number; noteId: number; text?: string; x?: number; y?: number; color?: string | null }): Promise<MapNote> => {
            const { data } = await apiClient.patch<{ data: MapNote }>(`/maps/${mapId}/notes/${noteId}`, { text, x, y, color });
            return data.data;
        },
        onSuccess: (_d, { mapId, text, color }) => {
            // Only refetch on a content change; a drag-only save leaves the cache alone.
            if (text !== undefined || color !== undefined) qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
        },
    });
}

/** Delete a note. */
export function useDeleteMapNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, noteId }: { mapId: number; noteId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/notes/${noteId}`);
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}
