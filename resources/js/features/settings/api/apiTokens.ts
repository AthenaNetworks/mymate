import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface ApiToken {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
}

const tokensKey = ['api-tokens'] as const;

export function useApiTokens() {
    return useQuery({
        queryKey: tokensKey,
        queryFn: async (): Promise<ApiToken[]> => {
            const { data } = await apiClient.get<ApiToken[]>('/api-tokens');
            return data;
        },
    });
}

// Minting returns the plaintext key exactly once - it's never retrievable again.
export interface MintedToken {
    id: number;
    name: string;
    token: string;
}

export function useCreateApiToken() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (name: string): Promise<MintedToken> => {
            const { data } = await apiClient.post<MintedToken>('/api-tokens', { name });
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: tokensKey }),
    });
}

export function useDeleteApiToken() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/api-tokens/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: tokensKey }),
    });
}
