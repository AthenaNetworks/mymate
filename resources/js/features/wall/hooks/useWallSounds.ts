import { useEffect, useRef } from 'react';
import { announce, armAlertSounds } from '../../../lib/alertSound';
import type { Device, DeviceStatus } from '../../../types';

/**
 * Alert sounds on a shared wallboard link. There's no live channel without a session, so this
 * compares each poll with the last one and announces the devices that really flipped up<->down
 * (so a change can be heard up to one poll late). The first poll only records where things
 * stand: opening the page never replays what was already down. Silent until someone turns it on
 * with the speaker button (the `shared` sound context starts off).
 */
export function useWallSounds(devices: Device[] | undefined): void {
    const last = useRef<Map<number, DeviceStatus> | null>(null);

    useEffect(() => armAlertSounds('shared'), []);

    useEffect(() => {
        if (!devices) return;
        const prev = last.current;
        last.current = new Map(devices.map((d) => [d.id, d.status]));
        if (prev === null) return;
        for (const d of devices) {
            const was = prev.get(d.id);
            if (!was || was === d.status || was === 'unknown' || d.status === 'unknown') continue;
            const name = d.alias?.trim() || d.name;
            announce('shared', { dir: d.status === 'down' ? 'down' : 'up', line: `${name} is ${d.status === 'down' ? 'down' : 'back up'}`, noun: 'device' });
        }
    }, [devices]);
}
