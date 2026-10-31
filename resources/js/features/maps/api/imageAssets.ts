import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { ImageAsset, MapImage } from '../../../types';
import { mapKeys } from './maps';
import { deviceKeys } from '../../devices/api/getDevices';

// The image library (GitHub #53): device icons and pictures placed on maps.

export const imageAssetKeys = { all: ['image-assets'] as const };

export function useImageAssets(enabled = true) {
    return useQuery({
        queryKey: imageAssetKeys.all,
        queryFn: async (): Promise<ImageAsset[]> => (await apiClient.get<{ data: ImageAsset[] }>('/image-assets')).data.data,
        enabled,
    });
}

export function useUploadImageAsset() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ file, name }: { file: File; name?: string }): Promise<ImageAsset> => {
            const form = new FormData();
            form.append('image', file);
            if (name) form.append('name', name);
            return (await apiClient.post<{ data: ImageAsset }>('/image-assets', form)).data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: imageAssetKeys.all }),
    });
}

/** Deleting takes it off every map and puts devices using it back on their automatic icon. */
export function useDeleteImageAsset() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/image-assets/${id}`);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: imageAssetKeys.all });
            qc.invalidateQueries({ queryKey: mapKeys.all });
            qc.invalidateQueries({ queryKey: deviceKeys.all });
        },
    });
}

export function useCreateMapImage() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, ...body }: { mapId: number; image_asset_id: number; x?: number; y?: number; width?: number }): Promise<MapImage> =>
            (await apiClient.post<{ data: MapImage }>(`/maps/${mapId}/images`, body)).data.data,
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

/** Resize (and the position the resize moved it to). A plain drag goes through the batch position save. */
export function useUpdateMapImage() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, imageId, ...body }: { mapId: number; imageId: number; x?: number; y?: number; width?: number }): Promise<MapImage> =>
            (await apiClient.patch<{ data: MapImage }>(`/maps/${mapId}/images/${imageId}`, body)).data.data,
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}

export function useDeleteMapImage() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ mapId, imageId }: { mapId: number; imageId: number }): Promise<void> => {
            await apiClient.delete(`/maps/${mapId}/images/${imageId}`);
        },
        onSuccess: (_d, { mapId }) => qc.invalidateQueries({ queryKey: mapKeys.detail(mapId) }),
    });
}
