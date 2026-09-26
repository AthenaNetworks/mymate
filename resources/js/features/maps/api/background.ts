import { useSyncExternalStore } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { MapBackground } from '../../../types';

// A map's custom background image (GitHub #37). Kept on its own query, apart from the map detail,
// so uploading or nudging it never invalidates the map and never rebuilds the canvas nodes. Every
// write updates this cache directly from the response. Writes are admin-only (the API enforces it).

export type BackgroundPlacement = Pick<MapBackground, 'x' | 'y' | 'scale' | 'opacity'>;

const bgKey = (mapId: number) => ['maps', mapId, 'background'] as const;

/** URL of the image for the logged-in app. `version` busts the cache when it's replaced. */
export function mapBackgroundUrl(mapId: number, bg: MapBackground): string {
    return `/api/maps/${mapId}/background/image?v=${encodeURIComponent(bg.version)}`;
}

export function useMapBackground(mapId: number | null) {
    return useQuery({
        queryKey: bgKey(mapId ?? 0),
        queryFn: async (): Promise<MapBackground | null> => {
            const { data } = await apiClient.get<{ data: MapBackground | null }>(`/maps/${mapId}/background`);
            return data.data;
        },
        enabled: mapId !== null,
        staleTime: 5 * 60_000, // only ever changes when an admin edits it, and that writes the cache
    });
}

export function useUploadMapBackground() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, file }: { mapId: number; file: File }): Promise<MapBackground> => {
            const body = new FormData();
            body.append('image', file);
            const { data } = await apiClient.post<{ data: MapBackground }>(`/maps/${mapId}/background`, body);
            return data.data;
        },
        onSuccess: (bg, { mapId }) => qc.setQueryData(bgKey(mapId), bg),
    });
}

export function useUpdateMapBackground() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, ...placement }: { mapId: number } & Partial<BackgroundPlacement>): Promise<MapBackground> => {
            const { data } = await apiClient.patch<{ data: MapBackground }>(`/maps/${mapId}/background`, placement);
            return data.data;
        },
        onSuccess: (bg, { mapId }) => qc.setQueryData(bgKey(mapId), bg),
    });
}

export function useDeleteMapBackground() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId }: { mapId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/background`);
        },
        onSuccess: (_d, { mapId }) => qc.setQueryData(bgKey(mapId), null),
    });
}

// Whether the placement panel is open. A tiny store of its own so the map menu (MapSwitcher) can
// open the panel that lives on the canvas without either one knowing about the other.
let editorOpen = false;
const listeners = new Set<() => void>();

export function setBackgroundEditorOpen(open: boolean): void {
    editorOpen = open;
    listeners.forEach((l) => l());
}

export function useBackgroundEditorOpen(): boolean {
    return useSyncExternalStore(
        (l) => {
            listeners.add(l);
            return () => listeners.delete(l);
        },
        () => editorOpen,
    );
}
