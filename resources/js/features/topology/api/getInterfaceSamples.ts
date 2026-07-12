import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { InterfaceSample } from '../../../types';

// Query keys for an interface\'s history series (co-located with the query).
export const sampleKeys = {
    all: ['interface-samples'] as const,
    series: (id: number, windowSec: number) => [...sampleKeys.all, id, windowSec] as const,
};

// 'YYYY-MM-DDTHH:MM:SS' in UTC, no offset - URL-safe and parsed as the app tz (UTC).
const stamp = (d: Date) => d.toISOString().slice(0, 19);

export async function fetchInterfaceSamples(id: number, windowSec: number): Promise<InterfaceSample[]> {
    const to = new Date();
    const from = new Date(to.getTime() - windowSec * 1000);
    const { data } = await apiClient.get<{ data: InterfaceSample[] }>(`/interfaces/${id}/samples`, {
        params: { from: stamp(from), to: stamp(to) },
    });
    return data.data;
}

/** History series for one interface over the trailing `windowSec`. Polls while shown. */
export function useInterfaceSamples(id: number | null, windowSec: number, enabled = true) {
    return useQuery({
        queryKey: sampleKeys.series(id ?? 0, windowSec),
        queryFn: () => fetchInterfaceSamples(id as number, windowSec),
        enabled: enabled && id !== null,
        refetchInterval: enabled ? 30_000 : false,
    });
}
