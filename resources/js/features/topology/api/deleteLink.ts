import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { linkKeys } from './getLinks';

export function useDeleteLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/links/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: linkKeys.all }),
    });
}
