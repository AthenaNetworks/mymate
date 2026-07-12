import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { subnetKeys } from './getSubnets';

export function useDeleteSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/subnets/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: subnetKeys.all }),
    });
}
