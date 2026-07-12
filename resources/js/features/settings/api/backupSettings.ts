import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/** API-safe view - never carries the token/SSH password (only whether one is stored). */
export interface BackupSettingsView {
    api_url: string;
    timeout: number;
    ssh_username: string;
    api_token_set: boolean;
    ssh_password_set: boolean;
    configured: boolean;
}

export interface BackupSettingsInput {
    api_url: string;
    timeout?: number;
    api_token?: string; // blank = keep the stored one
    ssh_username?: string;
    ssh_password?: string; // blank = keep the stored one
    ssh_enable?: string;
}

const backupSettingsKey = ['backup-settings'] as const;

export function useBackupSettings() {
    return useQuery({
        queryKey: backupSettingsKey,
        queryFn: async (): Promise<BackupSettingsView> => {
            const { data } = await apiClient.get<{ data: BackupSettingsView }>('/settings/backup');
            return data.data;
        },
    });
}

export function useUpdateBackupSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: BackupSettingsInput): Promise<BackupSettingsView> => {
            const { data } = await apiClient.put<{ data: BackupSettingsView }>('/settings/backup', input);
            return data.data;
        },
        onSuccess: (data) => qc.setQueryData(backupSettingsKey, data),
    });
}

/** Reachability check - does Rusted answer on the configured URL/token? */
export function useTestBackupEngine() {
    return useMutation({
        mutationFn: async (): Promise<{ ok: boolean }> => {
            const { data } = await apiClient.post<{ ok: boolean }>('/settings/backup/test');
            return data;
        },
    });
}
