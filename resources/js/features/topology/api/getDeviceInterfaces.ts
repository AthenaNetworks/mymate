import { useQuery, type QueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { InterfaceUtilFrame, InterfaceUtilUpdatedPayload, NetworkInterface } from '../../../types';

export function deviceInterfaceKeys(deviceId: number) {
    return ['devices', deviceId, 'interfaces'] as const;
}

export async function fetchDeviceInterfaces(deviceId: number): Promise<NetworkInterface[]> {
    const { data } = await apiClient.get<{ data: NetworkInterface[] }>(`/devices/${deviceId}/interfaces`);
    return data.data;
}

// What a live frame may set on a cached interface row. Anything else on the frame (speed, the
// old per-interface device_id/status) isn't the port list's business.
const LIVE_FIELDS = [
    'util_in', 'util_out', 'bps_in', 'bps_out', 'oper_status',
    'pkts_in', 'pkts_out', 'errors_in', 'errors_out', 'discards_in', 'discards_out',
    'optical_rx_dbm', 'optical_tx_dbm',
] as const;

/**
 * Fold a live util event into any cached interface lists (the inspector, the device page Ports tab
 * and the port page all read deviceInterfaceKeys). Only devices whose list is already cached are
 * touched, and only keys the frame actually has: the server leaves out whatever didn't change.
 */
export function patchCachedInterfaces(qc: QueryClient, e: InterfaceUtilUpdatedPayload) {
    for (const dev of e.devices) {
        const key = deviceInterfaceKeys(dev.device_id);
        if (!qc.getQueryData(key)) continue;
        const byId = new Map<number, InterfaceUtilFrame>();
        for (const f of dev.interfaces) byId.set(f.interface_id, f);
        for (const f of dev.ports ?? []) byId.set(f.interface_id, f);
        if (byId.size === 0) continue;

        qc.setQueryData<NetworkInterface[]>(key, (rows) => {
            if (!rows) return rows;
            let changed = false;
            const out = rows.map((row) => {
                const f = byId.get(row.id);
                if (!f) return row;
                const next = { ...row } as Record<string, unknown>;
                for (const k of LIVE_FIELDS) if (k in f) next[k] = f[k];
                // a new optical reading is as of now
                if ('optical_rx_dbm' in f || 'optical_tx_dbm' in f) next.optical_at = new Date().toISOString();
                changed = true;
                return next as unknown as NetworkInterface;
            });
            return changed ? out : rows;
        });
    }
}

/** A device's interfaces for the link binder; disabled until a device is chosen. */
export function useDeviceInterfaces(deviceId: number | null) {
    return useQuery({
        queryKey: deviceId === null ? ['devices', 'pending', 'interfaces'] : deviceInterfaceKeys(deviceId),
        queryFn: () => fetchDeviceInterfaces(deviceId as number),
        enabled: deviceId !== null,
    });
}
