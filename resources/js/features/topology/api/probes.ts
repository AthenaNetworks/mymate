import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Probe, ProbeKind, ProbeTestResult } from '../../../types';

// Service probes for a device (GitHub #19): HTTP/TCP checks with their own up/down + latency.

export interface ProbeInput {
    id?: number;
    device_id?: number;
    name: string;
    kind: ProbeKind;
    enabled?: boolean;
    interval_s?: number;
    timeout_ms?: number;
    fail_threshold?: number;
    config: Probe['config'];
}

const probeKeys = {
    forDevice: (deviceId: number) => ['probes', deviceId] as const,
};

export function useProbes(deviceId: number | null) {
    return useQuery({
        queryKey: probeKeys.forDevice(deviceId ?? 0),
        queryFn: async (): Promise<Probe[]> => {
            const { data } = await apiClient.get<{ data: Probe[] }>(`/devices/${deviceId}/probes`);
            return data.data;
        },
        enabled: deviceId !== null,
        // Poll so the status/latency stay current while the inspector is open (probes have no push).
        refetchInterval: 15000,
    });
}

export function useSaveProbe(deviceId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: ProbeInput): Promise<Probe> => {
            const { data } = input.id
                ? await apiClient.put<{ data: Probe }>(`/probes/${input.id}`, input)
                : await apiClient.post<{ data: Probe }>(`/devices/${deviceId}/probes`, input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: probeKeys.forDevice(deviceId) }),
    });
}

export function useDeleteProbe(deviceId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/probes/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: probeKeys.forDevice(deviceId) }),
    });
}

export function useTestProbe() {
    return useMutation({
        mutationFn: async (id: number): Promise<ProbeTestResult> => {
            const { data } = await apiClient.post<{ data: ProbeTestResult }>(`/probes/${id}/test`);
            return data.data;
        },
    });
}
