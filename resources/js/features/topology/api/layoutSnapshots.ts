import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

// Server-side layout undo stack, per map. A tidy snapshots the current positions first; undo pops
// the newest snapshot and restores it. Works from any browser (it lives on the server).

const countKey = (mapId: number) => ['layout-snapshots', mapId] as const;

/** How many undo steps are available for this map (drives the Undo button). */
export function useLayoutSnapshotCount(mapId: number | null) {
    return useQuery({
        queryKey: countKey(mapId ?? 0),
        enabled: mapId !== null,
        queryFn: async (): Promise<number> => {
            const { data } = await apiClient.get<{ count: number }>(`/maps/${mapId}/layout-snapshots`);
            return data.count;
        },
    });
}

/** Snapshot the map's current positions before a tidy re-arranges them. */
export function useCaptureLayoutSnapshot() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, note }: { mapId: number; note?: string }): Promise<number> => {
            const { data } = await apiClient.post<{ count: number }>(`/maps/${mapId}/layout-snapshots`, { note });
            return data.count;
        },
        onSuccess: (_c, { mapId }) => qc.invalidateQueries({ queryKey: countKey(mapId) }),
    });
}

export interface UndoResult {
    positions: Record<number, { x: number; y: number }>;
    remaining: number;
}

/** Roll back: restore the newest snapshot's positions and return them. */
export function useUndoLayout() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (mapId: number): Promise<UndoResult> => {
            const { data } = await apiClient.post<UndoResult>(`/maps/${mapId}/layout-snapshots/undo`);
            return data;
        },
        onSuccess: (_r, mapId) => qc.invalidateQueries({ queryKey: countKey(mapId) }),
    });
}
