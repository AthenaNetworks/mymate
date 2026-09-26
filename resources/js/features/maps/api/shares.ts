import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

// Public wallboard share links for a map (GitHub #15). Admin-only writes (the API enforces it);
// listing is allowed for any operator.
/** What a link shows (GitHub #37): the logical diagram, the geo map, or both with a switcher. */
export type MapShareView = 'logical' | 'geo' | 'both';

export interface MapShareLink {
    id: number;
    label: string | null;
    view: MapShareView;
    enabled: boolean;
    url: string;
    last_viewed_at: string | null;
    created_at: string | null;
}

const shareKeys = {
    list: (mapId: number) => ['maps', mapId, 'shares'] as const,
};

export function useMapShares(mapId: number | null) {
    return useQuery({
        queryKey: shareKeys.list(mapId ?? 0),
        queryFn: async (): Promise<MapShareLink[]> => {
            const { data } = await apiClient.get<{ data: MapShareLink[] }>(`/maps/${mapId}/shares`);
            return data.data;
        },
        enabled: mapId !== null,
    });
}

export function useCreateMapShare() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, label, view }: { mapId: number; label?: string; view?: MapShareView }): Promise<MapShareLink> => {
            const { data } = await apiClient.post<{ data: MapShareLink }>(`/maps/${mapId}/shares`, { label, view });
            return data.data;
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: shareKeys.list(mapId) }),
    });
}

export function useUpdateMapShare() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, id, ...body }: { mapId: number; id: number; label?: string | null; enabled?: boolean; view?: MapShareView }): Promise<MapShareLink> => {
            const { data } = await apiClient.patch<{ data: MapShareLink }>(`/maps/${mapId}/shares/${id}`, body);
            return data.data;
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: shareKeys.list(mapId) }),
    });
}

export function useDeleteMapShare() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, id }: { mapId: number; id: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/shares/${id}`);
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: shareKeys.list(mapId) }),
    });
}
