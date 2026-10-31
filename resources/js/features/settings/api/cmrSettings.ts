import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/** API-safe view - never carries the admin token (only whether one is stored). */
export interface CmrSettingsView {
    enabled: boolean;
    api_url: string;
    public_host: string; // blank = use this install's own host name
    effective_host: string;
    port: number;
    api_token_set: boolean;
    configured: boolean;
    // Read live from the daemon; null when it isn't reachable.
    identity: { fingerprint: string; listen_addr: string; version: string } | null;
}

export interface CmrSettingsInput {
    enabled: boolean;
    api_url: string;
    api_token?: string; // blank = keep the stored one
    public_host?: string;
}

const cmrSettingsKey = ['cmr-settings'] as const;

export function useCmrSettings() {
    return useQuery({
        queryKey: cmrSettingsKey,
        queryFn: async (): Promise<CmrSettingsView> => {
            const { data } = await apiClient.get<{ data: CmrSettingsView }>('/settings/cmr');
            return data.data;
        },
    });
}

export function useUpdateCmrSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: CmrSettingsInput): Promise<CmrSettingsView> => {
            const { data } = await apiClient.put<{ data: CmrSettingsView }>('/settings/cmr', input);
            return data.data;
        },
        onSuccess: (data) => qc.setQueryData(cmrSettingsKey, data),
    });
}

/** Reachability check - does cmr-ctrl answer on the configured URL/token? */
export function useTestCmrController() {
    return useMutation({
        mutationFn: async (): Promise<{ ok: boolean }> => {
            const { data } = await apiClient.post<{ ok: boolean }>('/settings/cmr/test');
            return data;
        },
    });
}
