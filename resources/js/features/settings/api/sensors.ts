import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { AlertScope, DeviceSensorReading, Sensor } from '../../../types';

export interface SensorInput {
    id?: number;
    name: string;
    oid: string;
    mode?: import('../../../types').SensorMode;
    agg?: import('../../../types').SensorAgg;
    unit?: string | null;
    divisor?: number;
    scope?: AlertScope;
    enabled?: boolean;
}

export function useSensors() {
    return useQuery({
        queryKey: ['sensors'],
        queryFn: async (): Promise<Sensor[]> => {
            const { data } = await apiClient.get<{ data: Sensor[] }>('/sensors');
            return data.data;
        },
    });
}

export function useSaveSensor() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (s: SensorInput): Promise<Sensor> => {
            const { data } = s.id
                ? await apiClient.put<{ data: Sensor }>(`/sensors/${s.id}`, s)
                : await apiClient.post<{ data: Sensor }>('/sensors', s);
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['sensors'] }),
    });
}

export function useDeleteSensor() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/sensors/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: ['sensors'] }),
    });
}

/** Current custom-sensor readings for one device (for the inspector). */
export function useDeviceSensors(deviceId: number | null) {
    return useQuery({
        queryKey: ['device-sensors', deviceId ?? 0],
        enabled: deviceId !== null,
        queryFn: async (): Promise<DeviceSensorReading[]> => {
            const { data } = await apiClient.get<{ data: DeviceSensorReading[] }>(`/devices/${deviceId}/sensors`);
            return data.data;
        },
        refetchInterval: 30_000,
    });
}
