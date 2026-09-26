import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../../../lib/echo';
import { useCurrentUser } from '../../auth/api/auth';
import { devicePageKeys } from './devicePage';
import type { Device, DeviceLatencyUpdatedPayload, DeviceMetricsUpdatedPayload, DeviceStatus } from '../../../types';

/**
 * Fold the live map channel's events for this one device into the page's device query, so
 * status, latency and cpu/mem tick on the Overview without a refetch. Listens on the same
 * channel useMapChannel does (the fleet one, or the per-user copy for a restricted operator)
 * but only adds and removes its own listeners, it never leaves the channel, so it can't tear
 * down a subscription another view is holding.
 */
export function useDeviceLive(deviceId: number) {
    const qc = useQueryClient();
    const { data: me } = useCurrentUser();
    const channelName = me ? (me.restricted ? `map.user.${me.id}` : 'map') : null;

    useEffect(() => {
        if (channelName === null) return;
        const channel = echo.private(channelName);
        const key = devicePageKeys.device(deviceId);
        const patch = (p: Partial<Device>) => qc.setQueryData<Device>(key, (d) => (d ? { ...d, ...p } : d));

        const onStatus = (e: { id: number; status: DeviceStatus; last_change: string | null }) => {
            if (e.id !== deviceId) return;
            patch({ status: e.status, last_change: e.last_change });
            void qc.invalidateQueries({ queryKey: ['device-page', deviceId, 'events'] });
        };
        const onLatency = (e: DeviceLatencyUpdatedPayload) => {
            const f = e.devices.find((d) => d.device_id === deviceId);
            if (f) patch({ rtt_ms: f.rtt_ms, loss_pct: f.loss_pct });
        };
        const onMetrics = (e: DeviceMetricsUpdatedPayload) => {
            const f = e.devices.find((d) => d.device_id === deviceId);
            if (f) {
                patch({
                    cpu_pct: f.cpu_pct, mem_used_pct: f.mem_used_pct, temp_c: f.temp_c, signal_dbm: f.signal_dbm,
                    snr_db: f.snr_db, ccq_pct: f.ccq_pct, wireless_clients: f.wireless_clients, ospf_neighbors: f.ospf_neighbors,
                });
            }
        };
        const onAlert = (e: { device_id: number | null }) => {
            if (e.device_id === deviceId) void qc.invalidateQueries({ queryKey: devicePageKeys.summary(deviceId) });
        };

        channel.listen('.DeviceStatusChanged', onStatus);
        channel.listen('.DeviceLatencyUpdated', onLatency);
        channel.listen('.DeviceMetricsUpdated', onMetrics);
        channel.listen('.AlertStateChanged', onAlert);
        return () => {
            channel.stopListening('.DeviceStatusChanged', onStatus);
            channel.stopListening('.DeviceLatencyUpdated', onLatency);
            channel.stopListening('.DeviceMetricsUpdated', onMetrics);
            channel.stopListening('.AlertStateChanged', onAlert);
        };
    }, [qc, deviceId, channelName]);
}
