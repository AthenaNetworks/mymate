import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { DiscoveryCandidate } from '../../../types';
import { candidateKeys } from './getCandidates';

export function useIgnoreCandidate() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<DiscoveryCandidate> => {
            const { data } = await apiClient.post<{ data: DiscoveryCandidate }>(`/discovery-candidates/${id}/ignore`);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: candidateKeys.all }),
    });
}
