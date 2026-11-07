import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { deviceKeys } from './getDevices';
import type { DeviceType, PollMethod } from '../../../types';

/** The fields a bulk edit can set - only the ones present are changed (DeviceFieldRules::BULK_FIELDS). */
export interface BulkDeviceChanges {
    poll_method?: PollMethod;
    credential_id?: number | null;
    monitored?: boolean;
    device_type?: DeviceType;
    agent_id?: number | null;
    parent_device_id?: number | null;
    ssh_credential_id?: number | null;
    routeros_credential_id?: number | null;
    ping_source?: string | null;
    latitude?: number | null;
    longitude?: number | null;
    icon?: string | null;
    icon_color?: string | null;
}

/** The 422 from a refused bulk edit: field problems under `changes.*`, per-device ones under `devices.<id>`. */
export type BulkErrors = Record<string, string[]>;

/** One set of changes to many devices, all or nothing (PATCH /devices/bulk). */
export function useBulkUpdateDevices() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ ids, changes }: { ids: number[]; changes: BulkDeviceChanges }): Promise<number> => {
            const { data } = await apiClient.patch<{ data: { updated: number } }>('/devices/bulk', { device_ids: ids, changes });
            return data.data.updated;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: deviceKeys.all });
            qc.invalidateQueries({ queryKey: ['geo', 'devices'] }); // moved pins
        },
    });
}

/** Every message in a refused bulk edit, flattened for display. */
export function bulkErrorMessages(err: unknown): string[] {
    const data = (err as { response?: { data?: { message?: string; errors?: BulkErrors } } } | null)?.response?.data;
    if (!data) return ["Couldn't save the changes."];
    if (data.errors) return Object.values(data.errors).flat();
    return [data.message ?? "Couldn't save the changes."];
}
