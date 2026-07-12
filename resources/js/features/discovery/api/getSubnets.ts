import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Subnet } from '../../../types';

// Query keys for the discovery subnets (co-located with the query).
export const subnetKeys = {
    all: ['subnets'] as const,
    list: () => [...subnetKeys.all, 'list'] as const,
};

async function fetchSubnets(): Promise<Subnet[]> {
    const { data } = await apiClient.get<{ data: Subnet[] }>('/subnets');
    return data.data;
}

export function useSubnets(opts?: { refetchInterval?: number | false }) {
    return useQuery({
        queryKey: subnetKeys.list(),
        queryFn: fetchSubnets,
        refetchInterval: opts?.refetchInterval ?? false,
    });
}
