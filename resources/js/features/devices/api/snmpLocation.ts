import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';
import type { Device } from '../../../types';

/**
 * Hand a hand-placed device back to its SNMP / RouterOS location (GitHub #22). `moved` is true
 * when the server already knew the coordinates and moved it now, false when the manual flag was
 * just dropped and the next discovery pass will place it.
 */
export function useRevertToSnmpLocation() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<{ device: Device; moved: boolean }> => {
            const { data } = await apiClient.post<{ data: Device; meta: { moved: boolean } }>(`/devices/${id}/use-snmp-location`);
            return { device: data.data, moved: data.meta.moved };
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}
