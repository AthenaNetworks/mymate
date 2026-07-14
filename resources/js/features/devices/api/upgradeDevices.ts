import { useMutation } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/**
 * Queue a firmware upgrade for one or many devices. Destructive (reboots gear) -
 * callers must confirm first. Status returns to the map live via Reverb once each
 * device reboots, so there\'s no cache to invalidate here.
 */
export type UpgradeSource = 'mikrotik' | 'mirror';

export interface UpgradeDevicesInput {
    deviceIds: number[];
    ordered?: boolean; // upgrade downstream-first, waiting for each to recover
    explicitOrder?: boolean; // keep deviceIds order as-is (operator re-ordered by hand)
    version?: string | null; // target RouterOS version; null/undefined = latest in channel
    source?: UpgradeSource; // where the router pulls the .npk from
}

export function useUpgradeDevices() {
    return useMutation({
        mutationFn: async ({ deviceIds, ordered, explicitOrder, version, source }: UpgradeDevicesInput): Promise<{ queued: number; ordered: boolean }> => {
            const { data } = await apiClient.post<{ queued: number; ordered: boolean }>('/devices/upgrade', {
                device_ids: deviceIds,
                ordered: ordered ?? false,
                explicit_order: explicitOrder ?? false,
                version: version ?? null,
                source: source ?? 'mikrotik',
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
    // Topology context so the operator can eyeball the order (furthest-out first).
    status: 'up' | 'down' | 'unknown';
    depth: number; // hops to the root - higher = further downstream
    os_version: string | null;
    latest_version: string | null;
    parent_name: string | null;
    neighbours: string[]; // linked peer device names
}
export interface UpgradePlan {
    order: number[];
    upgrade: number[];
    plan: UpgradePlanRow[];
}

export function useUpgradePreflight() {
    return useMutation({
        // preserveOrder true = preview in the given order (after a manual re-order) instead
        // of re-sorting furthest-first.
        mutationFn: async ({ deviceIds, preserveOrder, version }: { deviceIds: number[]; preserveOrder?: boolean; version?: string | null }): Promise<UpgradePlan> => {
            const { data } = await apiClient.post<UpgradePlan>('/devices/upgrade/preflight', {
                device_ids: deviceIds,
                preserve_order: preserveOrder ?? false,
                version: version ?? null,
            });
            return data;
        },
    });
}
