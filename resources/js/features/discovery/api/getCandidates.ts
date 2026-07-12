import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { DiscoveryCandidate, DiscoveryStatus } from '../../../types';

// Query keys for the discovery review queue (co-located with the query).
export const candidateKeys = {
    all: ['discovery-candidates'] as const,
    list: (status?: DiscoveryStatus) => [...candidateKeys.all, 'list', status ?? 'any'] as const,
};

async function fetchCandidates(status?: DiscoveryStatus): Promise<DiscoveryCandidate[]> {
    const { data } = await apiClient.get<{ data: DiscoveryCandidate[] }>('/discovery-candidates', {
        params: status ? { status } : undefined,
    });
    return data.data;
}

/**
 * The review queue. While the panel is open we poll so freshly-scanned candidates
 * surface without a manual refresh (discovery results aren't broadcast over Reverb).
 */
export function useCandidates(status: DiscoveryStatus | undefined, opts?: { enabled?: boolean; refetchMs?: number }) {
    const enabled = opts?.enabled ?? true;
    return useQuery({
        queryKey: candidateKeys.list(status),
        queryFn: () => fetchCandidates(status),
        enabled,
        refetchInterval: enabled ? (opts?.refetchMs ?? 10_000) : false,
    });
}
