import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';
import type { Device, DeviceType, PollMethod } from '../../../types';

type UpdateDeviceInput = {
    id: number;
    device_type?: DeviceType;
    name?: string;
    parent_device_id?: number | null;
    poll_method?: PollMethod;
};

/** Patch a device (e.g. reclassify its type). Invalidates the device list so the map
 *  node badge + sidebar update. */
export function useUpdateDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...body }: UpdateDeviceInput): Promise<Device> => {
            const { data } = await apiClient.patch<{ data: Device }>(`/devices/${id}`, body);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}
