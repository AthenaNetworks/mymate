import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { DeviceMetricSample } from '../../../types';

// Query keys for a device's cpu/mem/temp history series.
export const metricSampleKeys = {
    all: ['device-metric-samples'] as const,
    series: (id: number, windowSec: number) => [...metricSampleKeys.all, id, windowSec] as const,
};

const stamp = (d: Date) => d.toISOString().slice(0, 19);

async function fetchDeviceMetricSamples(id: number, windowSec: number): Promise<DeviceMetricSample[]> {
    const to = new Date();
    const from = new Date(to.getTime() - windowSec * 1000);
    const { data } = await apiClient.get<{ data: DeviceMetricSample[] }>(`/devices/${id}/metric-samples`, {
        params: { from: stamp(from), to: stamp(to) },
    });
    return data.data;
}

/** cpu/mem/temp history for one device over the trailing `windowSec`. Polls while shown. */
export function useDeviceMetricSamples(id: number | null, windowSec: number, enabled = true) {
    return useQuery({
        queryKey: metricSampleKeys.series(id ?? 0, windowSec),
        queryFn: () => fetchDeviceMetricSamples(id as number, windowSec),
        enabled: enabled && id !== null,
        refetchInterval: enabled ? 30_000 : false,
    });
}
