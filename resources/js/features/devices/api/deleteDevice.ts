import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';

export function useDeleteDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/devices/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}
