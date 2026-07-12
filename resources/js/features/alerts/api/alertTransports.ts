import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { AlertTransport } from '../../../types';

const transportsKey = ['alert-transports'] as const;

export function useAlertTransports() {
    return useQuery({
        queryKey: transportsKey,
        queryFn: async (): Promise<AlertTransport[]> => {
            const { data } = await apiClient.get<{ data: AlertTransport[] }>('/alert-transports');
            return data.data;
        },
    });
}

export interface AlertTransportInput {
    id?: number;
    name: string;
    type: 'email' | 'slack' | 'teams' | 'messenger';
    email?: string;
    webhook_url?: string;
    enabled?: boolean;
}

export function useSaveAlertTransport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: AlertTransportInput): Promise<AlertTransport> => {
            const { data } = id
                ? await apiClient.put<{ data: AlertTransport }>(`/alert-transports/${id}`, input)
                : await apiClient.post<{ data: AlertTransport }>('/alert-transports', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: transportsKey }),
    });
}

export function useDeleteAlertTransport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/alert-transports/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: transportsKey }),
    });
}

export function useTestTransport() {
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.post(`/alert-transports/${id}/test`);
        },
    });
}
