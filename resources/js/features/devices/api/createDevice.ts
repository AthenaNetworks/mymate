import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Device, DeviceType, PollMethod } from '../../../types';
import { deviceKeys } from './getDevices';

export interface CreateDeviceInput {
    name: string;
    mgmt_ip: string;
    poll_method: PollMethod;
    device_type?: DeviceType;
    credential_id?: number | null;
    agent_id?: number | null;
}

export function useCreateDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: CreateDeviceInput): Promise<Device> => {
            const { data } = await apiClient.post<{ data: Device }>('/devices', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}
