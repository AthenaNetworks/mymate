import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { UpdateStatus } from '../../../types';

/** Whether a newer My Mate release is out. Server-cached; polled infrequently. */
export function useUpdateCheck() {
    return useQuery({
        queryKey: ['update-check'],
        queryFn: async (): Promise<UpdateStatus> => {
            const { data } = await apiClient.get<{ data: UpdateStatus }>('/update-check');
            return data.data;
        },
        staleTime: 60 * 60 * 1000, // an hour; the backend caches the GitHub lookup longer
    });
}
