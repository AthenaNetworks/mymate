import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../../../lib/echo';
import { useCurrentUser } from '../../auth/api/auth';
import { devicePageKeys } from './devicePage';
import { deviceExtras, deviceKeys } from '../../devices/api/getDevices';
import { useWatchDevice } from '../../devices/api/watchDevice';
import { patchCachedInterfaces } from '../../topology/api/getDeviceInterfaces';
import type {
    Device,
    DeviceLatencyUpdatedPayload,
    DeviceMetricsUpdatedPayload,
    DeviceRebootedPayload,
    DeviceStatus,
    InterfaceUtilUpdatedPayload,
} from '../../../types';

/**
 * Fold the live map channel's events for this one device into the page's queries, so status,
 * latency, cpu/mem, uptime and per-CPU tick on the Overview, the ports tick on the Ports tab and
 * port page, and a reboot lands on the Events tab, all without a refetch. Listens on the same
 * channel useMapChannel does (the fleet one, or the per-user copy for a restricted operator) but
 * only adds and removes its own listeners, it never leaves the channel, so it can't tear down a
 * subscription another view is holding.
 *
 * Also marks the device as watched, which is what gets its non-link ports onto the stream at all.
 */
export function useDeviceLive(deviceId: number) {
    const qc = useQueryClient();
    const { data: me } = useCurrentUser();
    const channelName = me ? (me.restricted ? `map.user.${me.id}` : 'map') : null;
    useWatchDevice(deviceId);

    useEffect(() => {
        if (channelName === null) return;
        const channel = echo.private(channelName);
        const key = deviceKeys.detail(deviceId);
        const patch = (p: Partial<Device>) => qc.setQueryData<Device>(key, (d) => (d ? { ...d, ...p } : d));
        const refreshEvents = () => void qc.invalidateQueries({ queryKey: ['device-page', deviceId, 'events'] });

        const onStatus = (e: { id: number; status: DeviceStatus; last_change: string | null }) => {
            if (e.id !== deviceId) return;
            patch({ status: e.status, last_change: e.last_change });
            refreshEvents();
        };
        const onLatency = (e: DeviceLatencyUpdatedPayload) => {
            const f = e.devices.find((d) => d.device_id === deviceId);
            if (f) patch({ rtt_ms: f.rtt_ms, loss_pct: f.loss_pct });
        };
        const onMetrics = (e: DeviceMetricsUpdatedPayload) => {
            const f = e.devices.find((d) => d.device_id === deviceId);
            if (!f) return;
            patch({
                cpu_pct: f.cpu_pct, mem_used_pct: f.mem_used_pct, temp_c: f.temp_c, signal_dbm: f.signal_dbm,
                snr_db: f.snr_db, ccq_pct: f.ccq_pct, wireless_clients: f.wireless_clients, ospf_neighbors: f.ospf_neighbors,
                ...deviceExtras(f),
            });
            // Storage is just flagged (a list per device per tick would be a lot to push to every
            // map), so refetch the one small list, and only if it's on screen.
            if (f.storage) void qc.invalidateQueries({ queryKey: devicePageKeys.storage(deviceId) });
        };
        const onUtil = (e: InterfaceUtilUpdatedPayload) => {
            // the map canvas patches too when it's mounted, doing it twice is harmless
            if (e.devices.some((d) => d.device_id === deviceId)) {
                patchCachedInterfaces(qc, { ...e, devices: e.devices.filter((d) => d.device_id === deviceId) });
            }
        };
        const onReboot = (e: DeviceRebootedPayload) => {
            if (e.device_id === deviceId) refreshEvents();
        };
        const onAlert = (e: { device_id: number | null }) => {
            if (e.device_id === deviceId) void qc.invalidateQueries({ queryKey: devicePageKeys.summary(deviceId) });
        };

        channel.listen('.DeviceStatusChanged', onStatus);
        channel.listen('.DeviceLatencyUpdated', onLatency);
        channel.listen('.DeviceMetricsUpdated', onMetrics);
        channel.listen('.InterfaceUtilUpdated', onUtil);
        channel.listen('.DeviceRebooted', onReboot);
        channel.listen('.AlertStateChanged', onAlert);
        return () => {
            channel.stopListening('.DeviceStatusChanged', onStatus);
            channel.stopListening('.DeviceLatencyUpdated', onLatency);
            channel.stopListening('.DeviceMetricsUpdated', onMetrics);
            channel.stopListening('.InterfaceUtilUpdated', onUtil);
            channel.stopListening('.DeviceRebooted', onReboot);
            channel.stopListening('.AlertStateChanged', onAlert);
        };
    }, [qc, deviceId, channelName]);
}
