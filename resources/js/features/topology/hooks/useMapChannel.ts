import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../../../lib/echo';
import { useCurrentUser } from '../../auth/api/auth';
import { deviceExtras, deviceKeys, findCachedDevice, patchCachedDevices, type DeviceStats } from '../../devices/api/getDevices';
import type { GeoDevice } from '../../geo/api/sites';
import { outageKeys } from '../../outages/api/getOutages';
import { linkKeys } from '../api/getLinks';
import { deviceInterfaceKeys, patchCachedInterfaces } from '../api/getDeviceInterfaces';
import type {
    AlertStateChangedPayload,
    DeviceLatencyUpdatedPayload,
    DeviceMetricsUpdatedPayload,
    DeviceRebootedPayload,
    DeviceStatus,
    InterfaceUtilUpdatedPayload,
} from '../../../types';

type DeviceStatusChangedPayload = {
    id: number;
    status: DeviceStatus;
    last_change: string | null;
    // Newer servers only; older ones leave these out and we fall back to the cache.
    name?: string;
    previous_status?: DeviceStatus | null;
    monitored?: boolean;
};

/**
 * Single subscription to the private `map` channel:
 *  - `DeviceStatusChanged` -> folded into every cached copy of the device (map nodes, inspector,
 *    list pages), the geo feed and the header counts.
 *  - `InterfaceUtilUpdated` -> handed to `onUtil` so the caller can recolour edges live, and
 *    folded into any cached interface lists (inspector, device page ports).
 *  - `DeviceRebooted` -> handed to `onReboot` (the map toasts it).
 *
 * On (re)connect it resyncs the device + link snapshot to fill any missed events.
 * One subscription only (Echo caches the channel; a second `leave('map')` would
 * tear down the other listener).
 */
export function useMapChannel(
    onUtil?: (payload: InterfaceUtilUpdatedPayload) => void,
    onStatus?: (e: { id: number; name: string; status: DeviceStatus }) => void,
    onAlert?: (e: AlertStateChangedPayload) => void,
    onReboot?: (e: DeviceRebootedPayload) => void,
) {
    const qc = useQueryClient();
    // An unrestricted operator hears the whole fleet on the shared `map` channel. A restricted one
    // (per-user or via a group) isn't allowed on it: the server sends them a copy filtered to their
    // own devices on `map.user.{id}` instead, so nothing outside their maps reaches the socket.
    const { data: me } = useCurrentUser();
    const channelName = me ? (me.restricted ? `map.user.${me.id}` : 'map') : null;

    useEffect(() => {
        if (channelName === null) return;
        const channel = echo.private(channelName);

        // A real up<->down flip opens/closes an outage row, so the timeline is refreshed ahead
        // of its 15 s poll. Coalesced, not per-flip: on a large fleet hundreds of devices can
        // flap in a minute, and one invalidation per flip turned every open tab into a
        // ~100 req/s client of /api/outages. Trailing-edge so the last flip in a burst is
        // still reflected within the window.
        const OUTAGE_REFRESH_MS = 5_000;
        let outageTimer: ReturnType<typeof setTimeout> | null = null;
        let lastOutageRefresh = 0;
        const refreshOutagesSoon = () => {
            if (outageTimer !== null) return;
            const wait = Math.max(0, OUTAGE_REFRESH_MS - (Date.now() - lastOutageRefresh));
            outageTimer = setTimeout(() => {
                outageTimer = null;
                lastOutageRefresh = Date.now();
                void qc.invalidateQueries({ queryKey: outageKeys.all });
            }, wait);
        };

        // Custom broadcastAs() names -> leading dot so Echo doesn\'t prepend a namespace.
        channel.listen('.DeviceStatusChanged', (e: DeviceStatusChangedPayload) => {
            // The payload carries the name and the old status, so this works for devices that
            // aren't in any cached query (most of a big fleet, now there's no full list).
            const cached = findCachedDevice(qc, e.id);
            const prevStatus = e.previous_status ?? cached?.status ?? null;
            const name = e.name ?? cached?.name ?? `device ${e.id}`;

            patchCachedDevices(qc, (id) => (id === e.id ? { status: e.status, last_change: e.last_change } : null));
            qc.setQueryData<GeoDevice[]>(['geo', 'devices'], (prev) =>
                prev?.some((d) => d.id === e.id) ? prev.map((d) => (d.id === e.id ? { ...d, status: e.status } : d)) : prev,
            );

            // Move the device between the header tallies. Paused devices aren't counted there.
            if (prevStatus && prevStatus !== e.status && e.monitored !== false) {
                qc.setQueryData<DeviceStats>(deviceKeys.stats(), (s) =>
                    s ? { ...s, [prevStatus]: Math.max(0, s[prevStatus] - 1), [e.status]: s[e.status] + 1 } : s,
                );
            }

            // Notify only on a real up<->down flip. Skip the settle out of `unknown` (a fresh
            // device, or the first sweep after a restart), which on a large fleet would fire a
            // notification for every device at once.
            if (prevStatus && prevStatus !== e.status && prevStatus !== 'unknown') {
                onStatus?.({ id: e.id, name, status: e.status });
                refreshOutagesSoon();
            }
        });

        // An alert fired or resolved: refresh the Alerts views + the nav count, and for a port alert
        // the device's interface list so the inspector shows the port's new state straight away.
        channel.listen('.AlertStateChanged', (e: AlertStateChangedPayload) => {
            void qc.invalidateQueries({ queryKey: ['alert-events'] });
            if (e.interface_id !== null && e.device_id !== null) {
                void qc.invalidateQueries({ queryKey: deviceInterfaceKeys(e.device_id) });
            }
            onAlert?.(e);
        });

        channel.listen('.InterfaceUtilUpdated', (e: InterfaceUtilUpdatedPayload) => {
            onUtil?.(e);
            patchCachedInterfaces(qc, e);
        });

        channel.listen('.DeviceRebooted', (e: DeviceRebootedPayload) => {
            onReboot?.(e);
        });

        // Live cpu/mem/temp -> folded into the devices cache so both the map tiles and the
        // inspector show current values (slower cadence than util, so a cache write per
        // frame is cheap). Coalesced across devices in one event.
        channel.listen('.DeviceMetricsUpdated', (e: DeviceMetricsUpdatedPayload) => {
            const byId = new Map(e.devices.map((f) => [f.device_id, f]));
            patchCachedDevices(qc, (id) => {
                const f = byId.get(id);
                return f
                    ? {
                          cpu_pct: f.cpu_pct,
                          mem_used_pct: f.mem_used_pct,
                          temp_c: f.temp_c,
                          signal_dbm: f.signal_dbm,
                          snr_db: f.snr_db,
                          ccq_pct: f.ccq_pct,
                          wireless_clients: f.wireless_clients,
                          ospf_neighbors: f.ospf_neighbors,
                          ...deviceExtras(f),
                      }
                    : null;
            });
        });

        // Live ping latency/loss -> folded into the devices cache so the internet/upstream
        // card reflects current rtt without a refetch. Same coalesced shape as metrics.
        channel.listen('.DeviceLatencyUpdated', (e: DeviceLatencyUpdatedPayload) => {
            const byId = new Map(e.devices.map((f) => [f.device_id, f]));
            patchCachedDevices(qc, (id) => {
                const f = byId.get(id);
                return f ? { rtt_ms: f.rtt_ms, loss_pct: f.loss_pct } : null;
            });
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
            if (outageTimer !== null) clearTimeout(outageTimer);
            echo.leave(channelName);
        };
    }, [qc, onUtil, onStatus, onAlert, onReboot, channelName]);
}
