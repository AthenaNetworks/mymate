import { useMemo } from 'react';
import { usePlayback } from '../../geo/hooks/usePlayback';
import { frameLinks } from '../../geo/lib/playbackFrame';
import { computeData, linkUtil, metaOf, type UtilMap } from '../lib/edgeData';
import type { Device, DeviceStatus, Link } from '../../../types';

/**
 * History playback on the logical map (GitHub #22), the same usePlayback the geo map runs, shaped
 * for MapCanvas so the canvas itself only needs a few swaps:
 *
 *  - devices: the frame's devices (status, rtt/loss, cpu/mem/temp) in place of the live query.
 *  - links:   the frozen copy taken on entering playback. Structure only, it doesn't change per
 *             frame, so the edge list isn't rebuilt on every tick.
 *  - util / deviceUtil / deviceLoad: what the socket would normally feed, worked out from the
 *             frame instead. null when live, so the canvas keeps using its own socket-fed state,
 *             and while playing that state can carry on updating underneath without ever
 *             reaching the screen.
 *
 * Link colours still go through the canvas's own computeData with each link's effective speeds,
 * exactly as live. The tile's util is the busiest of the device's links by that same measure.
 */
export function useMapCanvasPlayback(mapId: number | null, liveDevices: Device[] | undefined, liveLinks: Link[] | undefined) {
    const pb = usePlayback(mapId, liveDevices, liveLinks);
    const snapshotLinks = pb.snapshot?.links;
    const frameDevices = pb.view.devices;
    const store = pb.store;
    const frame = pb.frame;

    const derived = useMemo(() => {
        if (!pb.active || !snapshotLinks) return null;
        const links = frameLinks(snapshotLinks, store, frame); // all blank until the first chunk lands
        const status: Record<number, DeviceStatus> = Object.fromEntries((frameDevices ?? []).map((d) => [d.id, d.status]));
        const util: UtilMap = {};
        const deviceUtil: Record<number, number | null> = {};
        const deviceLoad: Record<number, number | null> = {};
        const bump = (m: Record<number, number | null>, id: number, v: number | null) => {
            if (v !== null && (m[id] == null || v > (m[id] as number))) m[id] = v;
        };
        for (const l of links) {
            Object.assign(util, linkUtil(l));
            const { util: u } = computeData(metaOf(l), linkUtil(l), status);
            for (const [dev, iface] of [
                [l.a_device_id, l.a_interface],
                [l.b_device_id, l.b_interface],
            ] as const) {
                bump(deviceUtil, dev, u);
                if (iface && (iface.bps_in !== null || iface.bps_out !== null)) bump(deviceLoad, dev, Math.max(iface.bps_in ?? 0, iface.bps_out ?? 0));
            }
        }
        return { util, deviceUtil, deviceLoad };
        // pb.version: the store fills in place as chunks land
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pb.active, snapshotLinks, store, frame, pb.version, frameDevices]);

    return {
        pb,
        devices: pb.view.devices,
        links: pb.active && snapshotLinks ? snapshotLinks : liveLinks,
        util: derived?.util ?? null,
        deviceUtil: derived?.deviceUtil ?? null,
        deviceLoad: derived?.deviceLoad ?? null,
    };
}
