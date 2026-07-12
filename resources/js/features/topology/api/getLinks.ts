import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Link } from '../../../types';

// Query keys for the topology links (co-located with the query).
export const linkKeys = {
    all: ['links'] as const,
    list: () => [...linkKeys.all, 'list'] as const,
};

async function fetchLinks(): Promise<Link[]> {
    const { data } = await apiClient.get<{ data: Link[] }>('/links');
    return data.data;
}

export function useLinks() {
    return useQuery({ queryKey: linkKeys.list(), queryFn: fetchLinks });
}
