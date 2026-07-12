import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Subnet } from '../../../types';
import { subnetKeys } from './getSubnets';

/** Trigger an on-demand scan of a subnet (dispatched to the isolated scan queue). */
export function useScanSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<Subnet> => {
            const { data } = await apiClient.post<{ data: Subnet }>(`/subnets/${id}/scan`);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: subnetKeys.all }),
    });
}
