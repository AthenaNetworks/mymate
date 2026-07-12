import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Subnet } from '../../../types';
import { subnetKeys } from './getSubnets';

export interface UpdateSubnetInput {
    id: number;
    enabled?: boolean;
    label?: string | null;
    scan_interval_s?: number;
    cidr?: string;
}

export function useUpdateSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...patch }: UpdateSubnetInput): Promise<Subnet> => {
            const { data } = await apiClient.patch<{ data: Subnet }>(`/subnets/${id}`, patch);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: subnetKeys.all }),
    });
}
