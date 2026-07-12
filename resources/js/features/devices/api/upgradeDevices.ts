import { useMutation } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/**
 * Queue a firmware upgrade for one or many devices. Destructive (reboots gear) -
 * callers must confirm first. Status returns to the map live via Reverb once each
 * device reboots, so there\'s no cache to invalidate here.
 */
export interface UpgradeDevicesInput {
    deviceIds: number[];
    ordered?: boolean; // upgrade downstream-first, waiting for each to recover
}

export function useUpgradeDevices() {
    return useMutation({
        mutationFn: async ({ deviceIds, ordered }: UpgradeDevicesInput): Promise<{ queued: number; ordered: boolean }> => {
            const { data } = await apiClient.post<{ queued: number; ordered: boolean }>('/devices/upgrade', {
                device_ids: deviceIds,
                ordered: ordered ?? false,
            });
            return data;
        },
    });
}

// Dependency pre-flight: a dry-run that returns the downstream-first
// order + which devices would upgrade vs be skipped (and why), without touching anything.
export interface UpgradePlanRow {
    device_id: number;
    name: string;
    action: 'upgrade' | 'skip';
    reason: string | null;
}
export interface UpgradePlan {
    order: number[];
    upgrade: number[];
    plan: UpgradePlanRow[];
}

export function useUpgradePreflight() {
    return useMutation({
        mutationFn: async (deviceIds: number[]): Promise<UpgradePlan> => {
            const { data } = await apiClient.post<UpgradePlan>('/devices/upgrade/preflight', { device_ids: deviceIds });
            return data;
        },
    });
}
