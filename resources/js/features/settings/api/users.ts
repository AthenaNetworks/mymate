import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Operator } from '../../../types';

const usersKey = ['users'] as const;

/** The operator roster. The server shapes each row by the viewer's tier. */
export function useUsers() {
    return useQuery({
        queryKey: usersKey,
        queryFn: async (): Promise<Operator[]> => {
            const { data } = await apiClient.get<Operator[]>('/users');
            return data;
        },
    });
}

export interface UserInput {
    id?: number;
    name: string;
    email?: string;
    password?: string;
    is_admin: boolean;
    restricted?: boolean;
    map_ids?: number[];
}

export function useSaveUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...input }: UserInput): Promise<Operator> => {
            const { data } = id
                ? await apiClient.put<Operator>(`/users/${id}`, input)
                : await apiClient.post<Operator>('/users', input);
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: usersKey }),
    });
}

export function useDeleteUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/users/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: usersKey }),
    });
}
