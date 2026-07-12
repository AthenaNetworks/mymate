import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { InterfaceSample } from '../../../types';

// Query keys for a device's aggregate history series (total throughput).
export const deviceSampleKeys = {
    all: ['device-samples'] as const,
    series: (id: number, windowSec: number) => [...deviceSampleKeys.all, id, windowSec] as const,
};

const stamp = (d: Date) => d.toISOString().slice(0, 19);

export async function fetchDeviceSamples(id: number, windowSec: number): Promise<InterfaceSample[]> {
    const to = new Date();
    const from = new Date(to.getTime() - windowSec * 1000);
    const { data } = await apiClient.get<{ data: InterfaceSample[] }>(`/devices/${id}/samples`, {
        params: { from: stamp(from), to: stamp(to) },
    });
    return data.data;
}

/** Total-throughput history for a device (bps summed across its interfaces). */
export function useDeviceSamples(id: number | null, windowSec: number, enabled = true) {
    return useQuery({
        queryKey: deviceSampleKeys.series(id ?? 0, windowSec),
        queryFn: () => fetchDeviceSamples(id as number, windowSec),
        enabled: enabled && id !== null,
        refetchInterval: enabled ? 30_000 : false,
    });
}
