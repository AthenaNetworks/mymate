import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Device } from '../../../types';
import { deviceKeys } from '../../devices/api/getDevices';
import { candidateKeys } from './getCandidates';

/** Approve a candidate -> the API creates the device and starts polling it. */
export function useApproveCandidate() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<Device> => {
            const { data } = await apiClient.post<{ data: Device }>(`/discovery-candidates/${id}/approve`);
            return data.data;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: candidateKeys.all });
            qc.invalidateQueries({ queryKey: deviceKeys.all }); // the new device appears on the map
        },
    });
}
