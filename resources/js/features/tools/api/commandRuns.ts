import { useMutation, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface CommandDeviceResult {
    device_id: number;
    name: string;
    status: 'queued' | 'running' | 'success' | 'failed' | 'stopped';
    output: string;
    error: string | null;
}

export interface CommandRunSnapshot {
    run_id: string;
    kind: 'command';
    target: string;
    status: 'running' | 'done';
    result: {
        command: string;
        timeout: number;
        complete: boolean;
        devices: Record<string, CommandDeviceResult>;
    };
}

export function useStartCommandRun() {
    return useMutation({
        mutationFn: async (input: { device_ids: number[]; command: string; timeout: number; confirm: boolean; template_id?: number; command_hash?: string; template_updated_at?: string | null }) => {
            const { data } = await apiClient.post<{ run_id: string; kind: 'command'; status: 'running' }>('/command-runs', input);
            return data;
        },
    });
}

export function useCommandRun(runId: string | null) {
    return useQuery({
        queryKey: ['command-runs', runId],
        queryFn: async (): Promise<CommandRunSnapshot> => {
            const { data } = await apiClient.get<CommandRunSnapshot>(`/command-runs/${runId}`);
            return data;
        },
        enabled: Boolean(runId),
        refetchInterval: (query) => query.state.data?.status === 'done' ? false : 1000,
    });
}

export function useStopCommandRun() {
    return useMutation({
        mutationFn: async (runId: string) => {
            await apiClient.delete(`/command-runs/${runId}`);
        },
    });
}
