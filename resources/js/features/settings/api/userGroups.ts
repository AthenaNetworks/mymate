import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { OperatorGroup } from '../../../types';

const groupsKey = ['user-groups'] as const;

/** Operator groups (GitHub #28). Admin-only on the server, so only fetch when `enabled`. */
export function useUserGroups(enabled = true) {
    return useQuery({
        queryKey: groupsKey,
        enabled,
        queryFn: async (): Promise<OperatorGroup[]> => {
            const { data } = await apiClient.get<OperatorGroup[]>('/user-groups');
            return data;
        },
    });
}

export interface UserGroupInput {
    id?: number;
    name: string;
    description?: string | null;
    restricted: boolean;
    map_ids: number[];
    user_ids: number[];
}

// Membership shows on both the group and the operator rows, so refresh both after a change.
function useInvalidate() {
    const qc = useQueryClient();
    return () => {
        qc.invalidateQueries({ queryKey: groupsKey });
        qc.invalidateQueries({ queryKey: ['users'] });
    };
}

export function useSaveUserGroup() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: async ({ id, ...input }: UserGroupInput): Promise<OperatorGroup> => {
            const { data } = id
                ? await apiClient.put<OperatorGroup>(`/user-groups/${id}`, input)
                : await apiClient.post<OperatorGroup>('/user-groups', input);
            return data;
        },
        onSuccess: invalidate,
    });
}

export function useDeleteUserGroup() {
    const invalidate = useInvalidate();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/user-groups/${id}`);
        },
        onSuccess: invalidate,
    });
}
