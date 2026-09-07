import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';
import { mapKeys } from '../../maps/api/maps';

/**
 * Take a device off every map at once (it stays monitored, keeps its links + history). Both the
 * device list (maps_count) and every map payload change, so both caches are invalidated.
 */
export function useUnplaceDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/devices/${id}/map-positions`);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: deviceKeys.all });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}
