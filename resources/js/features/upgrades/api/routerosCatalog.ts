import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface ChannelRelease {
    major: number;
    channel: string;
    version: string;
    released_at: number | null;
}

export interface RouterosPackage {
    id: number;
    version: string;
    arch: string;
    channel: string | null;
    status: 'pending' | 'ready' | 'failed';
    size_bytes: number | null;
    error: string | null;
    fetched_at: string | null;
    download_url: string | null;
}

export interface RouterosCatalog {
    channels: ChannelRelease[];
    arches: string[];
    device_arches: string[];
    retention_days: number;
    packages: RouterosPackage[];
}

export function useRouterosCatalog() {
    return useQuery({
        queryKey: ['routeros-catalog'],
        queryFn: async (): Promise<RouterosCatalog> => {
            const { data } = await apiClient.get<{ data: RouterosCatalog }>('/routeros/catalog');
            return data.data;
        },
        // Poll while packages are downloading so the cache list updates.
        refetchInterval: (query) =>
            query.state.data?.packages.some((p) => p.status === 'pending') ? 4000 : 60_000,
    });
}

export function useFetchPackage() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: { version: string; arch: string; channel?: string | null }): Promise<RouterosPackage> => {
            const { data } = await apiClient.post<{ data: RouterosPackage }>('/routeros/packages', {
                version: input.version,
                arch: input.arch,
                channel: input.channel ?? null,
            });
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['routeros-catalog'] }),
    });
}

export function useDeletePackage() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/routeros/packages/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['routeros-catalog'] }),
    });
}
