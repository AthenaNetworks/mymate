import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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

/**
 * Alerts firing right now, for the live count on the Alerts nav item (GitHub #22). Keyed under
 * 'alert-events' so a pushed AlertStateChanged refreshes it at once; the interval covers views
 * without the live channel. Off for restricted operators - alert history isn't theirs to read.
 */
export function useFiringAlerts(enabled: boolean) {
    return useQuery({
        queryKey: ['alert-events', 'firing'],
        queryFn: async (): Promise<AlertEvent[]> => {
            const { data } = await apiClient.get<{ data: AlertEvent[] }>('/alert-events', { params: { status: 'firing' } });
            return data.data;
        },
        enabled,
        refetchInterval: 30000,
    });
}

/** Toggle acknowledgement on a fired alert. */
export function useAckAlertEvent() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<AlertEvent> => {
            const { data } = await apiClient.post<{ data: AlertEvent }>(`/alert-events/${id}/ack`);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['alert-events'] }),
    });
}
