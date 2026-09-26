import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

// Origins allowed to embed the public wallboard in an iframe (GitHub #15). Admin-only both ways.
export interface WallEmbedSettings {
    frame_ancestors: string[];
}

const wallEmbedKey = ['wall-embed-settings'] as const;

export function useWallEmbedSettings() {
    return useQuery({
        queryKey: wallEmbedKey,
        queryFn: async (): Promise<WallEmbedSettings> => (await apiClient.get<{ data: WallEmbedSettings }>('/settings/wall-embed')).data.data,
    });
}

export function useUpdateWallEmbedSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (origins: string[]): Promise<WallEmbedSettings> =>
            (await apiClient.put<{ data: WallEmbedSettings }>('/settings/wall-embed', { frame_ancestors: origins })).data.data,
        onSuccess: (data) => qc.setQueryData(wallEmbedKey, data),
    });
}
