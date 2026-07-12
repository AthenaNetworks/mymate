import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Credential } from '../../../types';

const credentialsKey = ['credentials'] as const;

export function useCredentials() {
    return useQuery({
        queryKey: credentialsKey,
        queryFn: async (): Promise<Credential[]> => {
            const { data } = await apiClient.get<{ data: Credential[] }>('/credentials');
            return data.data;
        },
    });
}

export interface CredentialInput {
    id?: number;
    name: string;
    type: 'snmp' | 'routeros';
    snmp_community?: string;
    username?: string;
    password?: string;
    api_port?: number | null;
}

export function useSaveCredential() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: CredentialInput): Promise<Credential> => {
            const { data } = id
                ? await apiClient.put<{ data: Credential }>(`/credentials/${id}`, input)
                : await apiClient.post<{ data: Credential }>('/credentials', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: credentialsKey }),
    });
}

export function useDeleteCredential() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/credentials/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: credentialsKey }),
    });
}
