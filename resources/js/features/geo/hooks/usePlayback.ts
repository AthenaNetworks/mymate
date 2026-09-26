import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { deviceKeys } from '../../devices/api/getDevices';
import { linkKeys } from '../../topology/api/getLinks';
import { fetchPlayback } from '../api/playback';
import { createStore, frameAt, frameDevices, frameLinks, mergeChunk, nextUnloaded, sameAxis, type PlaybackStore } from '../lib/playbackFrame';
import type { Device, Link } from '../../../types';

/** Frames per second at 1x. */
const BASE_FPS = 2;
export const PLAYBACK_SPEEDS = [1, 2, 4, 8] as const;

export const PLAYBACK_PRESETS = [
    { label: '1h', hours: 1 },
    { label: '6h', hours: 6 },
    { label: '24h', hours: 24 },
    { label: '7d', hours: 24 * 7 },
] as const;

type Window = { from: number; to: number; hours: number; focus: number | null }; // unix ms, focus = frame to open on

export type Playback = ReturnType<typeof usePlayback>;

/**
 * Historical playback state for the geo map (GitHub #22), kept out of the canvas itself.
 *
 * Entering playback freezes a copy of the devices and links as they are right now; every frame is
 * drawn from that copy with the frame's status/rtt/bps swapped in, so the live socket can keep
 * updating the query cache underneath without ever touching what's on screen. Going back to live
 * drops the copy and refetches devices and links, so anything missed while scrubbing is caught up.
 *
 * The window loads in chunks (the server sizes them to the map), starting from wherever the
 * playhead is, so a big map can start playing after the first chunk while the rest streams in.
 */
export function usePlayback(mapId: number | null, devices: Device[] | undefined, links: Link[] | undefined) {
    const qc = useQueryClient();
    const [active, setActive] = useState(false);
    const [snapshot, setSnapshot] = useState<{ devices: Device[]; links: Link[] } | null>(null);
    const [win, setWin] = useState<Window | null>(null);
    const [store, setStore] = useState<PlaybackStore | null>(null);
    const [version, setVersion] = useState(0); // bumped as chunks land in the (mutable) store
    const [frame, setFrameState] = useState(0);
    const [playing, setPlaying] = useState(false);
    const [speed, setSpeed] = useState<number>(1);
    const [error, setError] = useState<string | null>(null);
    const frameRef = useRef(0);

    const setFrame = useCallback((i: number) => {
        frameRef.current = i;
        setFrameState(i);
    }, []);

    const exit = useCallback(() => {
        setActive(false);
        setPlaying(false);
        setSnapshot(null);
        setWin(null);
        setStore(null);
        setError(null);
        // back to live: catch up on whatever the socket changed while we were looking at the past
        void qc.invalidateQueries({ queryKey: deviceKeys.all });
        void qc.invalidateQueries({ queryKey: linkKeys.all });
    }, [qc]);

    /** Open (or move) the window. `at` centres it on that moment and opens on its frame. */
    const load = useCallback(
        (hours: number, at: number | null = null) => {
            if (!devices || !links) return;
            if (!active) {
                setSnapshot({ devices, links });
                setActive(true);
            }
            const now = Date.now();
            const span = hours * 3600_000;
            const to = at === null ? now : Math.min(now, at + span / 2);
            setWin({ from: to - span, to, hours, focus: at });
            setPlaying(false);
        },
        [active, devices, links],
    );

    // Switching maps leaves playback, the window belongs to the old map's devices.
    useEffect(() => {
        if (active) exit();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mapId]);

    // Page the window in: the first chunk brings the axis and outages, then keep asking for the
    // first frames not loaded yet from the playhead on until there are none left.
    useEffect(() => {
        if (!win || mapId === null) return;
        const ctrl = new AbortController();
        const q = { from: new Date(win.from).toISOString(), to: new Date(win.to).toISOString() };
        setStore(null);
        setError(null);

        (async () => {
            try {
                const first = await fetchPlayback(mapId, q, ctrl.signal);
                const s = createStore(first);
                const start = win.focus === null ? 0 : frameAt(s, Math.floor(win.focus / 1000));
                setFrame(start);
                setStore(s);
                const chunk = Math.max(1, first.limit);
                for (;;) {
                    const at = nextUnloaded(s, frameRef.current);
                    if (at === null || ctrl.signal.aborted) break;
                    let run = 0;
                    while (at + run < s.loaded.length && !s.loaded[at + run] && run < chunk) run++;
                    const next = await fetchPlayback(mapId, { ...q, offset: at, limit: run }, ctrl.signal);
                    if (!sameAxis(s, next)) break; // shouldn't happen for a fixed from/to, but never mix windows
                    mergeChunk(s, next);
                    setVersion((v) => v + 1);
                }
            } catch (e) {
                if (!ctrl.signal.aborted) setError(e instanceof Error ? e.message : 'Could not load history');
            }
        })();

        return () => ctrl.abort();
    }, [win, mapId, setFrame]);

    // Playing: step at the chosen speed, holding on a frame whose data hasn't arrived yet.
    useEffect(() => {
        if (!playing || !store) return;
        const id = setInterval(() => {
            const next = frameRef.current + 1;
            if (next >= store.frames.length) {
                setPlaying(false);
                return;
            }
            if (store.loaded[next]) setFrame(next);
        }, 1000 / (BASE_FPS * speed));
        return () => clearInterval(id);
    }, [playing, speed, store, setFrame]);

    const step = useCallback(
        (d: number) => {
            if (!store) return;
            setPlaying(false);
            setFrame(Math.max(0, Math.min(store.frames.length - 1, frameRef.current + d)));
        },
        [store, setFrame],
    );

    const togglePlay = useCallback(() => {
        if (!store) return;
        // at the end, play from the top again
        if (!playing && frameRef.current >= store.frames.length - 1) setFrame(0);
        setPlaying((p) => !p);
    }, [store, playing, setFrame]);

    // The frame to draw. Outside playback the live data passes straight through.
    const view = useMemo(() => {
        if (!active || !snapshot) return { devices, links };
        if (!store) return { devices: snapshot.devices, links: snapshot.links };
        return { devices: frameDevices(snapshot.devices, store, frame), links: frameLinks(snapshot.links, store, frame) };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, snapshot, store, frame, version, devices, links]);

    const loadedCount = useMemo(() => (store ? store.loaded.filter(Boolean).length : 0), [store, version]); // eslint-disable-line react-hooks/exhaustive-deps

    return {
        active,
        window: win,
        store,
        version,
        frame,
        frameLoaded: store?.loaded[frame] ?? false,
        loadedCount,
        playing,
        speed,
        error,
        view,
        canStart: !!devices && !!links && mapId !== null,
        load,
        exit,
        setFrame: (i: number) => {
            setPlaying(false);
            setFrame(i);
        },
        step,
        togglePlay,
        setSpeed,
    };
}
