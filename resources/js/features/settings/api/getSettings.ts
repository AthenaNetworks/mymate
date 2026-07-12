import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { EngineSetting } from '../../../types';

const settingsKey = ['settings'] as const;

export function useSettings() {
    return useQuery({
        queryKey: settingsKey,
        queryFn: async (): Promise<EngineSetting[]> => {
            const { data } = await apiClient.get<{ data: EngineSetting[] }>('/settings');
            return data.data;
        },
    });
}

export function useUpdateSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (settings: { key: string; value: number }[]): Promise<EngineSetting[]> => {
            const { data } = await apiClient.put<{ data: EngineSetting[] }>('/settings', { settings });
            return data.data;
        },
        onSuccess: (data) => qc.setQueryData(settingsKey, data),
    });
}
