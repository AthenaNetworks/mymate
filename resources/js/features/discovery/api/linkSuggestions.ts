import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { linkKeys } from '../../topology/api/getLinks';
import type { LinkSuggestion } from '../../../types';

// Suggested links from LLDP / CDP / MNDP neighbours (GitHub #85).
export const linkSuggestionKeys = {
    all: ['link-suggestions'] as const,
    list: (status: LinkSuggestion['status']) => [...linkSuggestionKeys.all, status] as const,
};

/** Polled while the Discovery page is open: suggestions arrive on the discovery cadence. */
export function useLinkSuggestions(status: LinkSuggestion['status']) {
    return useQuery({
        queryKey: linkSuggestionKeys.list(status),
        queryFn: async () => (await apiClient.get<{ data: LinkSuggestion[] }>('/link-suggestions', { params: { status } })).data.data,
        refetchInterval: 30_000,
    });
}

type Action = 'accept' | 'ignore' | 'restore';

export function useLinkSuggestionAction(action: Action) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number) => (await apiClient.post(`/link-suggestions/${id}/${action}`)).data,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: linkSuggestionKeys.all });
            if (action === 'accept') qc.invalidateQueries({ queryKey: linkKeys.all });
        },
    });
}

export function useAcceptAllLinkSuggestions() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async () => (await apiClient.post<{ data: { created: number } }>('/link-suggestions/accept-all')).data.data,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: linkSuggestionKeys.all });
            qc.invalidateQueries({ queryKey: linkKeys.all });
        },
    });
}
