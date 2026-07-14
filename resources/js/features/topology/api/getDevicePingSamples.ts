import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { DevicePingSample } from '../../../types';

// Query keys for a device's latency/loss/jitter history series.
export const pingSampleKeys = {
    all: ['device-ping-samples'] as const,
    series: (id: number, windowSec: number) => [...pingSampleKeys.all, id, windowSec] as const,
};

const stamp = (d: Date) => d.toISOString().slice(0, 19);

async function fetchDevicePingSamples(id: number, windowSec: number): Promise<DevicePingSample[]> {
    const to = new Date();
    const from = new Date(to.getTime() - windowSec * 1000);
    const { data } = await apiClient.get<{ data: DevicePingSample[] }>(`/devices/${id}/ping-samples`, {
        params: { from: stamp(from), to: stamp(to) },
    });
    return data.data;
}

/** Latency/loss/jitter history for one device over the trailing `windowSec`. Polls while shown. */
export function useDevicePingSamples(id: number | null, windowSec: number, enabled = true) {
    return useQuery({
        queryKey: pingSampleKeys.series(id ?? 0, windowSec),
        queryFn: () => fetchDevicePingSamples(id as number, windowSec),
        enabled: enabled && id !== null,
        refetchInterval: enabled ? 30_000 : false,
    });
}
