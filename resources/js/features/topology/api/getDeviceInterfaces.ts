import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { NetworkInterface } from '../../../types';

export function deviceInterfaceKeys(deviceId: number) {
    return ['devices', deviceId, 'interfaces'] as const;
}

async function fetchDeviceInterfaces(deviceId: number): Promise<NetworkInterface[]> {
    const { data } = await apiClient.get<{ data: NetworkInterface[] }>(`/devices/${deviceId}/interfaces`);
    return data.data;
}

/** A device's interfaces for the link binder; disabled until a device is chosen. */
export function useDeviceInterfaces(deviceId: number | null) {
    return useQuery({
        queryKey: deviceId === null ? ['devices', 'pending', 'interfaces'] : deviceInterfaceKeys(deviceId),
        queryFn: () => fetchDeviceInterfaces(deviceId as number),
        enabled: deviceId !== null,
    });
}
