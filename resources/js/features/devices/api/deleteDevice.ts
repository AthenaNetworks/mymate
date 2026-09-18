import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';
import { linkKeys } from '../../topology/api/getLinks';
import { mapKeys } from '../../maps/api/maps';

/**
 * Delete a device outright - it stops being monitored, and the database cascade takes its
 * interfaces, links, map placements, probes and history with it.
 *
 * That cascade is why all three caches are invalidated (GitHub #45): leaving the link and map
 * payloads stale made the map keep drawing edges and inter-map portals for a device that was
 * already gone, until something else happened to refetch them.
 */
export function useDeleteDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/devices/${id}`);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: deviceKeys.all });
            qc.invalidateQueries({ queryKey: linkKeys.all });
            qc.invalidateQueries({ queryKey: mapKeys.all });
        },
    });
}
