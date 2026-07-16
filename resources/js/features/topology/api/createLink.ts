import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Link, LinkMediaType } from '../../../types';
import { linkKeys } from './getLinks';

export interface CreateLinkInput {
    a_device_id: number;
    a_interface_id: number | null; // null = ping-only end (no interface)
    b_device_id: number;
    b_interface_id: number | null;
    a_handle?: string | null; // node side each end attaches to (from the drag); null = auto
    b_handle?: string | null;
    media_type?: LinkMediaType | null; // physical medium (map styling); null = unspecified
}

export function useCreateLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: CreateLinkInput): Promise<Link> => {
            const { data } = await apiClient.post<{ data: Link }>('/links', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: linkKeys.all }),
    });
}
