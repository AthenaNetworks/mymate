import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/** Wipe all monitoring data (devices, maps, credentials, history), keeping only admin accounts.
 *  Password-confirmed on the server. Clears the whole query cache on success so the UI empties. */
export function useFactoryReset() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (password: string): Promise<{ message: string }> => {
            const { data } = await apiClient.post<{ message: string }>('/system/factory-reset', { password });
            return data;
        },
        onSuccess: () => qc.invalidateQueries(),
    });
}
