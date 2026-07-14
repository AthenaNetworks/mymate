import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { AlertScope, MaintenanceWindow } from '../../../types';

export interface MaintenanceWindowInput {
    id?: number;
    name: string;
    starts_at: string;
    ends_at: string;
    scope?: AlertScope;
    enabled?: boolean;
}

export function useMaintenanceWindows() {
    return useQuery({
        queryKey: ['maintenance-windows'],
        queryFn: async (): Promise<MaintenanceWindow[]> => {
            const { data } = await apiClient.get<{ data: MaintenanceWindow[] }>('/maintenance-windows');
            return data.data;
        },
    });
}

export function useSaveMaintenanceWindow() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (w: MaintenanceWindowInput): Promise<MaintenanceWindow> => {
            const { data } = w.id
                ? await apiClient.put<{ data: MaintenanceWindow }>(`/maintenance-windows/${w.id}`, w)
                : await apiClient.post<{ data: MaintenanceWindow }>('/maintenance-windows', w);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['maintenance-windows'] }),
    });
}

export function useDeleteMaintenanceWindow() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/maintenance-windows/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['maintenance-windows'] }),
    });
}
