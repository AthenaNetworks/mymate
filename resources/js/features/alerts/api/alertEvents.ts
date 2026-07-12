import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { AlertEvent } from '../../../types';

export function useAlertEvents() {
    return useQuery({
        queryKey: ['alert-events'],
        queryFn: async (): Promise<AlertEvent[]> => {
            const { data } = await apiClient.get<{ data: AlertEvent[] }>('/alert-events');
            return data.data;
        },
        refetchInterval: 15000,
    });
}
