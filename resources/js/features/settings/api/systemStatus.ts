import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type StatusLevel = 'ok' | 'warn' | 'down';
export type SystemCheck = { key: string; label: string; status: StatusLevel; detail: string };

/** Live health board (db/redis/workers/polling/websockets/backups) for Settings. */
export function useSystemStatus() {
    return useQuery({
        queryKey: ['system-status'],
        queryFn: async (): Promise<SystemCheck[]> => {
            const { data } = await apiClient.get<{ data: SystemCheck[] }>('/system-status');
            return data.data;
        },
        refetchInterval: 30_000,
    });
}
