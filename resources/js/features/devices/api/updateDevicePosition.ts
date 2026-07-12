import { useMutation } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface PositionInput {
    id: number;
    map_x: number;
    map_y: number;
}

// Persists a node\'s position on drag-stop. No cache invalidation - the position
// already lives in React Flow\'s local node state, so a refetch would be wasteful.
export function useUpdateDevicePosition() {
    return useMutation({
        mutationFn: async ({ id, map_x, map_y }: PositionInput): Promise<void> => {
            await apiClient.patch(`/devices/${id}/position`, { map_x, map_y });
        },
    });
}
