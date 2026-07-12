import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { pushToast } from '../../../lib/toast';

/**
 * Trigger on-demand interface (re)discovery for a device. The job
 * runs async on the queue, so we refresh the device + its interfaces a few times
 * over the next several seconds to pick up the result (or the new discovery_error).
 */
export function useDiscoverDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (device: { id: number; name: string }): Promise<void> => {
            await apiClient.post(`/devices/${device.id}/discover`);
        },
        onSuccess: (_data, device) => {
            pushToast({ tone: 'info', title: `Discovering ${device.name}...`, detail: 'Interfaces will appear shortly.' });
            // ['devices'] is the prefix for both the device list and per-device interface queries.
            const refresh = () => qc.invalidateQueries({ queryKey: ['devices'] });
            refresh();
            window.setTimeout(refresh, 3000);
            window.setTimeout(refresh, 7000);
        },
    });
}
