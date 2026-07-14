import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { AlertConditionType, AlertPolicy, AlertScope } from '../../../types';

const policiesKey = ['alert-policies'] as const;

export function useAlertPolicies() {
    return useQuery({
        queryKey: policiesKey,
        queryFn: async (): Promise<AlertPolicy[]> => {
            const { data } = await apiClient.get<{ data: AlertPolicy[] }>('/alert-policies');
            return data.data;
        },
    });
}

export interface AlertPolicyInput {
    id?: number;
    name: string;
    condition: AlertConditionType;
    params?: {
        threshold?: number;
        duration_minutes?: number;
        suppress_dependent?: boolean;
        metric?: 'cpu' | 'mem' | 'temp' | 'latency' | 'loss';
    };
    scope?: AlertScope;
    enabled?: boolean;
    transport_ids?: number[];
}

export function useSaveAlertPolicy() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: AlertPolicyInput): Promise<AlertPolicy> => {
            const { data } = id
                ? await apiClient.put<{ data: AlertPolicy }>(`/alert-policies/${id}`, input)
                : await apiClient.post<{ data: AlertPolicy }>('/alert-policies', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: policiesKey }),
    });
}

export function useDeleteAlertPolicy() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/alert-policies/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: policiesKey }),
    });
}
