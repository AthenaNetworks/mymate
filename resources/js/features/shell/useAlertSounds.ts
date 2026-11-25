import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '../../lib/echo';
import { announce, armAlertSounds, getSoundPrefsSnapshot } from '../../lib/alertSound';
import { findCachedDevice } from '../devices/api/getDevices';
import { useCurrentUser } from '../auth/api/auth';
import type { AlertStateChangedPayload, DeviceStatus } from '../../types';

type StatusPayload = {
    id: number;
    status: DeviceStatus;
    name?: string;
    previous_status?: DeviceStatus | null;
    monitored?: boolean;
};

/**
 * Feeds alert sounds for a signed-in operator, on every page and in wallboard mode. Listens on
 * the same live channel as the map (the shared `map` one, or a restricted operator's own copy)
 * and announces, per Settings > Sounds, either every real device up<->down flip or the alerts of
 * the ticked types firing and resolving. Mounted once, in the app shell.
 *
 * It only adds its own listeners and removes just those again, never leaving the channel, so the
 * map's own subscription (useMapChannel) and this one don't tear each other down.
 */
export function useAlertSounds(): void {
    const qc = useQueryClient();
    const { data: me } = useCurrentUser();
    const channelName = me ? (me.restricted ? `map.user.${me.id}` : 'map') : null;

    useEffect(() => armAlertSounds('app'), []);

    useEffect(() => {
        if (channelName === null) return;
        const channel = echo.private(channelName);
        // What we last heard for each device, for servers that don't send previous_status.
        const last = new Map<number, DeviceStatus>();

        const onStatus = (e: StatusPayload) => {
            const cached = findCachedDevice(qc, e.id);
            const prev = e.previous_status ?? last.get(e.id) ?? (cached && cached.status !== e.status ? cached.status : null);
            last.set(e.id, e.status);
            if (getSoundPrefsSnapshot('app').source !== 'devices') return;
            if (e.monitored === false) return; // paused devices aren't watched
            // Only a real up<->down flip: the settle out of `unknown` (a new device, or the first
            // sweep after a restart) would otherwise sound for the whole fleet at once.
            if (!prev || prev === e.status || prev === 'unknown' || e.status === 'unknown') return;
            const name = e.name ?? cached?.alias?.trim() ?? cached?.name ?? `Device ${e.id}`;
            announce('app', { dir: e.status === 'down' ? 'down' : 'up', line: `${name} is ${e.status === 'down' ? 'down' : 'back up'}`, noun: 'device' });
        };

        const onAlert = (e: AlertStateChangedPayload) => {
            const p = getSoundPrefsSnapshot('app');
            if (p.source !== 'alerts' || e.condition === null || !p.alertTypes.includes(e.condition)) return;
            announce('app', { dir: e.state === 'firing' ? 'down' : 'up', line: e.state === 'firing' ? e.message || 'Alert firing' : `Resolved: ${e.message || 'alert'}`, noun: 'alert' });
        };

        channel.listen('.DeviceStatusChanged', onStatus);
        channel.listen('.AlertStateChanged', onAlert);
        return () => {
            channel.stopListening('.DeviceStatusChanged', onStatus);
            channel.stopListening('.AlertStateChanged', onAlert);
        };
    }, [qc, channelName]);
}
