import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Outage } from '../../../types';

export const outageKeys = {
    all: ['outages'] as const,
    list: (state: string) => ['outages', state] as const,
};

async function fetchOutages(state?: 'open' | 'closed'): Promise<Outage[]> {
    const { data } = await apiClient.get<{ data: Outage[] }>('/outages', { params: state ? { state } : {} });
    return data.data;
}

/** The outage timeline, optionally filtered to open/closed. Polls so it stays fresh
 *  as devices go down/recover (outages aren't pushed over the socket). */
export function useOutages(state?: 'open' | 'closed') {
    return useQuery({
        queryKey: outageKeys.list(state ?? 'all'),
        queryFn: () => fetchOutages(state),
        refetchInterval: 15000,
    });
}
