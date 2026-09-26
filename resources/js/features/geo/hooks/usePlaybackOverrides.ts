import { useMemo } from 'react';
import { useActiveMapId } from '../../../lib/shellStore';
import { frameDevice, frameInterface } from '../lib/playbackFrame';
import { usePlaybackView } from '../lib/playbackState';
import type { Device, NetworkInterface } from '../../../types';

export interface PlaybackOverrides<D, I> {
    device: D;
    interfaces: I;
    /** Set while the map is in playback: the frame on screen, for the inspector's banner. */
    playback: {
        at: number | null; // unix seconds, null until the first chunk is in
        step: number | null; // how wide the frame is, seconds
        instant: boolean; // the ?at= single moment view
        onMap: boolean; // false when the selected device isn't part of what's being played back
        exit: () => void;
    } | null;
}

/**
 * The device inspector's view of a device during map playback (GitHub #22): the live device and
 * its ports with the current frame's values laid over them (status, latency/loss, cpu/mem/temp,
 * bps per port), so the panel shows the same moment as the canvas and nothing live leaks in.
 * Outside playback, or on a different map, it hands the live values straight back.
 */
export function usePlaybackOverrides<D extends Device | undefined, I extends NetworkInterface[] | undefined>(device: D, interfaces: I): PlaybackOverrides<D, I> {
    const pb = usePlaybackView();
    const activeMapId = useActiveMapId();
    const store = pb?.store ?? null;
    const frame = pb?.frame ?? 0;
    const version = pb?.version ?? 0;

    return useMemo(() => {
        if (!pb || pb.mapId !== activeMapId) return { device, interfaces, playback: null };
        // a device that isn't on the map being played back has nothing in the frame, so it stays live
        const onMap = !!device && (store === null || store.deviceIds.has(device.id));
        const playback = {
            at: store?.frames[frame] ?? null,
            step: store?.step ?? null,
            instant: store?.mode === 'at',
            onMap,
            exit: pb.exit,
        };
        if (!device || !onMap) return { device, interfaces, playback };
        return {
            device: frameDevice(device, store, frame) as D,
            interfaces: interfaces?.map((i) => frameInterface(i, store, frame)) as I,
            playback,
        };
        // version: the store fills in place as chunks land
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pb, activeMapId, store, frame, version, device, interfaces]);
}
