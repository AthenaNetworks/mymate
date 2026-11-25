import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { useDebounced } from '../../devices/api/getDevices';
import type { AlertScope, ScopePreview } from '../../../types';

/**
 * How many devices a targeting scope covers, for the scope editor's "Matches N devices" line.
 * Debounced, so ticking several boxes asks once; the previous answer stays shown meanwhile.
 * Admin-only on the server, like the editors that use it.
 */
export function useScopePreview(scope: AlertScope, enabled = true) {
    const key = useDebounced(JSON.stringify(scope), 300);
    return useQuery({
        queryKey: ['scope-preview', key] as const,
        queryFn: async (): Promise<ScopePreview> => {
            const { data } = await apiClient.post<{ data: ScopePreview }>('/scopes/preview', { scope: JSON.parse(key) as AlertScope });
            return data.data;
        },
        enabled,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
    });
}
