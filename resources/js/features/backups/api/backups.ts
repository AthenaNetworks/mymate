import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from '../../devices/api/getDevices';
import type { BackupHistoryEntry, BackupScheduleConfig, BackupVersion, Device } from '../../../types';

/**
 * Device config-backup hooks. History + latest-config read through the
 * Rusted engine via My Mate\'s proxy routes; config + run are admin writes that invalidate
 * the device list so the inspector\'s cached backup mirror refreshes.
 */

export const backupKeys = {
    all: ['backups'] as const,
    history: (deviceId: number) => [...backupKeys.all, 'history', deviceId] as const,
    config: (deviceId: number) => [...backupKeys.all, 'config', deviceId] as const,
};

/** Backup history for a device (newest first), proxied from Rusted. */
export function useDeviceBackups(deviceId: number | null) {
    return useQuery({
        queryKey: backupKeys.history(deviceId ?? 0),
        enabled: deviceId !== null,
        queryFn: async (): Promise<BackupHistoryEntry[]> => {
            const { data } = await apiClient.get<{ data: BackupHistoryEntry[] }>(`/devices/${deviceId}/backups`);
            return data.data;
        },
    });
}

/** Latest stored config text for a device (fetched lazily - only when a viewer opens it). */
export function useDeviceLatestConfig(deviceId: number | null, enabled: boolean) {
    return useQuery({
        queryKey: backupKeys.config(deviceId ?? 0),
        enabled: deviceId !== null && enabled,
        queryFn: async (): Promise<string | null> => {
            const { data } = await apiClient.get<{ data: { config: string | null } }>(`/devices/${deviceId}/backups/latest`);
            return data.data.config;
        },
    });
}

/** Turn backups on/off + set the driver for a device. */
export function useUpdateBackupConfig() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, backup_enabled, backup_driver }: { id: number; backup_enabled: boolean; backup_driver?: string | null }): Promise<Device> => {
            const { data } = await apiClient.put<{ data: Device }>(`/devices/${id}/backup-config`, { backup_enabled, backup_driver });
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}

/**
 * Bootstrap key-based SSH on a MikroTik over its API (RouterOS can't export over the API).
 * Rusted installs a generated key; My Mate stores it and switches the device to SSH backups.
 */
export function useProvisionSshKey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (deviceId: number): Promise<{ message: string; ssh_enabled: boolean; ssh_enabled_by: boolean }> => {
            const { data } = await apiClient.post(`/devices/${deviceId}/provision-ssh-key`);
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}

/** Config version history for a device (git commits, newest first). */
export function useDeviceVersions(deviceId: number | null) {
    return useQuery({
        queryKey: [...backupKeys.all, 'versions', deviceId ?? 0],
        enabled: deviceId !== null,
        queryFn: async (): Promise<BackupVersion[]> => {
            const { data } = await apiClient.get<{ data: BackupVersion[] }>(`/devices/${deviceId}/backups/versions`);
            return data.data;
        },
    });
}

/** Stored config text at a specific commit (for viewing an older version). */
export function useDeviceConfigAt(deviceId: number | null, commit: string | null) {
    return useQuery({
        queryKey: [...backupKeys.all, 'config-at', deviceId ?? 0, commit ?? ''],
        enabled: deviceId !== null && commit !== null,
        queryFn: async (): Promise<string | null> => {
            const { data } = await apiClient.get<{ data: { config: string | null } }>(`/devices/${deviceId}/backups/config`, { params: { commit } });
            return data.data.config;
        },
    });
}

/** Unified diff of a device's config. `from` alone shows what that backup changed. */
export function useDeviceDiff(deviceId: number | null, from: string | null, to: string | null) {
    return useQuery({
        queryKey: [...backupKeys.all, 'diff', deviceId ?? 0, from ?? '', to ?? ''],
        enabled: deviceId !== null && from !== null,
        queryFn: async (): Promise<string | null> => {
            const { data } = await apiClient.get<{ data: { diff: string | null } }>(`/devices/${deviceId}/backups/diff`, { params: { from, to: to ?? '' } });
            return data.data.diff;
        },
    });
}

/** The automatic-backup schedule. */
export function useBackupSchedule() {
    return useQuery({
        queryKey: ['backup-schedule'],
        queryFn: async (): Promise<BackupScheduleConfig> => {
            const { data } = await apiClient.get<{ data: BackupScheduleConfig }>('/settings/backup-schedule');
            return data.data;
        },
    });
}

/** Save the automatic-backup schedule. */
export function useUpdateBackupSchedule() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: Omit<BackupScheduleConfig, 'last_run_at'>): Promise<BackupScheduleConfig> => {
            const { data } = await apiClient.put<{ data: BackupScheduleConfig }>('/settings/backup-schedule', input);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['backup-schedule'] }),
    });
}

/** Queue a backup now for every backup-enabled device. */
export function useRunAllBackups() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<{ queued: number }> => {
            const { data } = await apiClient.post<{ queued: number }>('/backups/run-all');
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: deviceKeys.all }),
    });
}

/** Queue a backup now. The device is marked `pending`; invalidate so the spinner shows. */
export function useRunBackup() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (deviceId: number): Promise<Device> => {
            const { data } = await apiClient.post<{ data: Device }>(`/devices/${deviceId}/backups`);
            return data.data;
        },
        onSuccess: (_data, deviceId) => {
            qc.invalidateQueries({ queryKey: deviceKeys.all });
            qc.invalidateQueries({ queryKey: backupKeys.history(deviceId) });
        },
    });
}
