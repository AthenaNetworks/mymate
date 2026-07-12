import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Agent } from '../../../types';

const agentsKey = ['agents'] as const;

export function useAgents() {
    return useQuery({
        queryKey: agentsKey,
        // Poll so the online/offline status stays fresh in the UI.
        refetchInterval: 15_000,
        queryFn: async (): Promise<Agent[]> => {
            const { data } = await apiClient.get<{ data: Agent[] }>('/agents');
            return data.data;
        },
    });
}

// Enrolment returns the plaintext token exactly once.
export interface EnrolledAgent {
    agent: { data: Agent };
    token: string;
}

export function useEnrolAgent() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (name: string): Promise<EnrolledAgent> => {
            const { data } = await apiClient.post<EnrolledAgent>('/agents', { name });
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: agentsKey }),
    });
}

export function useDeleteAgent() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/agents/${id}`);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: agentsKey });
            qc.invalidateQueries({ queryKey: ['devices'] }); // devices revert to central polling
        },
    });
}
