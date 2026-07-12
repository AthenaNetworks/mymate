import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { BACKUP_IN_PROGRESS, UPGRADE_IN_PROGRESS, type Device } from '../../../types';

// Query keys for the devices feature (co-located with the queries).
export const deviceKeys = {
    all: ['devices'] as const,
    list: () => [...deviceKeys.all, 'list'] as const,
};

async function fetchDevices(): Promise<Device[]> {
    const { data } = await apiClient.get<{ data: Device[] }>('/devices');
    return data.data;
}

export function useDevices() {
    return useQuery({
        queryKey: deviceKeys.list(),
        queryFn: fetchDevices,
        // While any device is mid-upgrade or mid-backup, poll so the spinner advances + resolves.
        refetchInterval: (query) =>
            (query.state.data ?? []).some(
                (d) =>
                    (d.upgrade_status && UPGRADE_IN_PROGRESS.has(d.upgrade_status)) ||
                    (d.backup_status && BACKUP_IN_PROGRESS.has(d.backup_status)),
            )
                ? 3000
                : false,
    });
}
