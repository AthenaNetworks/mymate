import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Graph, GraphConfig, GraphData } from '../../../types';

// Saved custom graphs (GitHub #28).

export interface GraphInput {
    id?: number;
    name: string;
    config: GraphConfig;
}

const graphKeys = {
    all: ['graphs'] as const,
    data: (id: number, range: string) => ['graphs', id, 'data', range] as const,
};

export function useGraphs() {
    return useQuery({
        queryKey: graphKeys.all,
        queryFn: async (): Promise<Graph[]> => {
            const { data } = await apiClient.get<{ data: Graph[] }>('/graphs');
            return data.data;
        },
    });
}

export function useSaveGraph() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: GraphInput): Promise<Graph> => {
            const { data } = input.id
                ? await apiClient.put<{ data: Graph }>(`/graphs/${input.id}`, input)
                : await apiClient.post<{ data: Graph }>('/graphs', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: graphKeys.all }),
    });
}

export function useDeleteGraph() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/graphs/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: graphKeys.all }),
    });
}

export function useGraphData(id: number | null, range: string) {
    return useQuery({
        queryKey: graphKeys.data(id ?? 0, range),
        queryFn: async (): Promise<GraphData> => {
            const { data } = await apiClient.get<{ data: GraphData }>(`/graphs/${id}/data`, { params: { range } });
            return data.data;
        },
        enabled: id !== null,
        // Keep a live-ish view without hammering; the newest bucket fills in as polling runs.
        refetchInterval: 30000,
    });
}
