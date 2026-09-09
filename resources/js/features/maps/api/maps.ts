import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { pushToast } from '../../../lib/toast';
import type { LinkMediaType, MapDetail, MapLink, MapNote, MapNoteSize, NetworkMap } from '../../../types';

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

/** One gesture's worth of moved nodes - any mix of the four node kinds; each list optional. */
export interface MapPositionBatch {
    devices?: { id: number; x: number; y: number }[];
    portals?: { link_id: number; x: number; y: number }[];
    child_maps?: { id: number; x: number; y: number }[];
    notes?: { id: number; x: number; y: number }[];
}

export function isEmptyBatch(b: MapPositionBatch): boolean {
    return !b.devices?.length && !b.portals?.length && !b.child_maps?.length && !b.notes?.length;
}

/** Fold a batch of moves into a cached MapDetail so the canvas and the cache agree immediately. */
function applyBatch(detail: MapDetail, b: MapPositionBatch): MapDetail {
    const next = { ...detail };
    if (b.devices?.length) {
        const byId = new Map(b.devices.map((d) => [d.id, d]));
        next.positions = detail.positions.map((p) => {
            const m = byId.get(p.device_id);
            return m ? { ...p, x: m.x, y: m.y } : p;
        });
    }
    if (b.portals?.length) {
        const byId = new Map(b.portals.map((pt) => [pt.link_id, pt]));
        next.inter_map_links = detail.inter_map_links.map((il) => {
            const m = byId.get(il.id);
            return m ? { ...il, portal_x: m.x, portal_y: m.y } : il;
        });
    }
    if (b.child_maps?.length) {
        const byId = new Map(b.child_maps.map((c) => [c.id, c]));
        next.child_maps = detail.child_maps.map((c) => {
            const m = byId.get(c.id);
            return m ? { ...c, node_x: m.x, node_y: m.y } : c;
        });
    }
    if (b.notes?.length) {
        const byId = new Map(b.notes.map((n) => [n.id, n]));
        next.map_notes = detail.map_notes.map((n) => {
            const m = byId.get(n.id);
            return m ? { ...n, x: m.x, y: m.y } : n;
        });
    }
    return next;
}

/**
 * Persist every node a single gesture moved (a multi-select drag, Tidy) in ONE request
 * (GitHub #44). The cached map detail is patched optimistically first - and any refetch
 * already in flight is cancelled - so a refresh landing mid-drag can't bounce the nodes back
 * to their old spots. On failure the cache is rolled back (the nodes visibly revert) and a
 * toast says so, rather than the canvas silently disagreeing with the server.
 */
export function useSaveMapPositions() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, ...batch }: { mapId: number } & MapPositionBatch): Promise<void> => {
            await apiClient.patch(`/maps/${mapId}/positions`, batch);
        },
        onMutate: async ({ mapId, ...batch }) => {
            const key = mapKeys.detail(mapId);
            // A refetch already in flight (e.g. the one an "add device" just triggered) would land
            // AFTER our patch carrying pre-drag positions. Cancel it; it's re-run once the save
            // has succeeded (see onSuccess) so whatever it was fetching for still arrives.
            const interrupted = qc.isFetching({ queryKey: key }) > 0;
            await qc.cancelQueries({ queryKey: key });
            const previous = qc.getQueryData<MapDetail>(key);
            if (previous) qc.setQueryData<MapDetail>(key, applyBatch(previous, batch));
            return { previous, interrupted };
        },
        onSuccess: async (_d, { mapId, ...batch }, ctx) => {
            // A refetch started DURING the PATCH could have replaced the cache with pre-save
            // positions. Now the server has them, re-apply the batch and refresh from the source.
            const key = mapKeys.detail(mapId);
            const raced = qc.isFetching({ queryKey: key }) > 0;
            if (raced) await qc.cancelQueries({ queryKey: key });
            const current = qc.getQueryData<MapDetail>(key);
            if (current) qc.setQueryData<MapDetail>(key, applyBatch(current, batch));
            if (raced || ctx?.interrupted) void qc.invalidateQueries({ queryKey: key });
        },
        onError: (_err, { mapId }, ctx) => {
            if (ctx?.previous) qc.setQueryData<MapDetail>(mapKeys.detail(mapId), ctx.previous);
            pushToast({ title: 'Couldn\'t save the new positions', detail: 'The nodes were put back where the server has them.', tone: 'down', key: 'map-positions-save' });
            void qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
        },
    });
}

/**
 * Place a device on a map. Pass x/y to land it exactly there in the same call - a separate
 * position save after the add used to race the refetch this triggers, so a dropped device could
 * briefly (or, on a slow link, lastingly) show at 0,0 (GitHub #44).
 */
export function useAddDeviceToMap() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, deviceId, x, y }: { mapId: number; deviceId: number; x?: number; y?: number }): Promise<void> => {
            await apiClient.post(`/maps/${mapId}/devices`, { device_id: deviceId, x, y });
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

/** Update a manual link's medium / label / attachment sides. */
export function useUpdateMapLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, mapLinkId, ...body }: { mapId: number; mapLinkId: number; media_type?: LinkMediaType | null; label?: string | null; a_handle?: string | null; b_handle?: string | null }): Promise<MapLink> => {
            const { data } = await apiClient.patch<{ data: MapLink }>(`/maps/${mapId}/map-links/${mapLinkId}`, body);
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

/** Update a note's text / position / style. Position saves aren't invalidated (already moved). */
export function useUpdateMapNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (
            { mapId, noteId, text, x, y, color, background, size }:
            { mapId: number; noteId: number; text?: string; x?: number; y?: number; color?: string | null; background?: string | null; size?: MapNoteSize | null },
        ): Promise<MapNote> => {
            const { data } = await apiClient.patch<{ data: MapNote }>(`/maps/${mapId}/notes/${noteId}`, { text, x, y, color, background, size });
            return data.data;
        },
        onSuccess: (_d, { mapId, text, color, background, size }) => {
            // Only refetch on a content/style change; a drag-only save leaves the cache alone.
            if (text !== undefined || color !== undefined || background !== undefined || size !== undefined) {
                qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) });
            }
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
