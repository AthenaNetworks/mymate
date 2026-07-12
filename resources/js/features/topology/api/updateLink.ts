import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Link } from '../../../types';
import { linkKeys } from './getLinks';

export interface UpdateLinkInput {
    id: number;
    a_device_id: number;
    a_interface_id: number;
    b_device_id: number;
    b_interface_id: number;
    // Per-link bandwidth override A->B / B->A; null reverts to the derived speed.
    bw_ab_mbps?: number | null;
    bw_ba_mbps?: number | null;
}

export function useUpdateLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...body }: UpdateLinkInput): Promise<Link> => {
            const { data } = await apiClient.put<{ data: Link }>(`/links/${id}`, body);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: linkKeys.all }),
    });
}
