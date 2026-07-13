import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../../../lib/echo';
import { deviceKeys } from '../../devices/api/getDevices';
import { linkKeys } from '../api/getLinks';
import type { Device, DeviceMetricsUpdatedPayload, DeviceStatus, InterfaceUtilUpdatedPayload } from '../../../types';

type DeviceStatusChangedPayload = {
    id: number;
    status: DeviceStatus;
    last_change: string | null;
};

/**
 * Single subscription to the private `map` channel:
 *  - `DeviceStatusChanged` -> folded into the devices query cache (sidebar + node dots).
 *  - `InterfaceUtilUpdated` -> handed to `onUtil` so the caller can recolour edges live.
 *
 * On (re)connect it resyncs the device + link snapshot to fill any missed events.
 * One subscription only (Echo caches the channel; a second `leave('map')` would
 * tear down the other listener).
 */
export function useMapChannel(
    onUtil?: (payload: InterfaceUtilUpdatedPayload) => void,
    onStatus?: (e: { id: number; name: string; status: DeviceStatus }) => void,
) {
    const qc = useQueryClient();

    useEffect(() => {
        const channel = echo.private('map');

        // Custom broadcastAs() names -> leading dot so Echo doesn\'t prepend a namespace.
        channel.listen('.DeviceStatusChanged', (e: DeviceStatusChangedPayload) => {
            const before = qc.getQueryData<Device[]>(deviceKeys.list())?.find((d) => d.id === e.id);

            qc.setQueryData<Device[]>(
                deviceKeys.list(),
                (prev) =>
                    prev?.map((d) => (d.id === e.id ? { ...d, status: e.status, last_change: e.last_change } : d)) ??
                    prev,
            );

            // Notify only on a real flip (skip the first unknown->up settle if you prefer).
            if (before && before.status !== e.status) {
                onStatus?.({ id: e.id, name: before.name, status: e.status });
            }
        });

        channel.listen('.InterfaceUtilUpdated', (e: InterfaceUtilUpdatedPayload) => {
            onUtil?.(e);
        });

        // Live cpu/mem/temp -> folded into the devices cache so both the map tiles and the
        // inspector show current values (slower cadence than util, so a cache write per
        // frame is cheap). Coalesced across devices in one event.
        channel.listen('.DeviceMetricsUpdated', (e: DeviceMetricsUpdatedPayload) => {
            const byId = new Map(e.devices.map((f) => [f.device_id, f]));
            qc.setQueryData<Device[]>(
                deviceKeys.list(),
                (prev) =>
                    prev?.map((d) => {
                        const f = byId.get(d.id);
                        return f ? { ...d, cpu_pct: f.cpu_pct, mem_used_pct: f.mem_used_pct, temp_c: f.temp_c } : d;
                    }) ?? prev,
            );
        });

        const connection = (
            echo.connector as unknown as {
                pusher?: { connection?: { bind(event: string, cb: () => void): void } };
            }
        ).pusher?.connection;
        connection?.bind('connected', () => {
            void qc.invalidateQueries({ queryKey: deviceKeys.all });
            void qc.invalidateQueries({ queryKey: linkKeys.all });
        });

        return () => {
            echo.leave('map');
        };
    }, [qc, onUtil, onStatus]);
}
