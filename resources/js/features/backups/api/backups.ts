import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from '../../devices/api/getDevices';
import type { BackupHistoryEntry, Device } from '../../../types';

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
