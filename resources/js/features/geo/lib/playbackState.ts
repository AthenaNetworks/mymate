import { useSyncExternalStore } from 'react';
import type { PlaybackStore } from './playbackFrame';

/**
 * What the map canvas is playing back right now (GitHub #22), for anything outside the canvas that
 * has to follow along, the device inspector mainly. Same tiny external store pattern as
 * lib/shellStore, so the inspector reads it without the playback state being threaded through
 * AppShell. Only one canvas is mounted at a time (geo or logical), and its usePlayback is the only
 * writer. null means live.
 */
export interface PlaybackView {
    mapId: number;
    store: PlaybackStore | null; // null while the first chunk is still loading
    frame: number;
    version: number; // bumps as chunks land in the (mutable) store
    exit: () => void;
}

let current: PlaybackView | null = null;
const listeners = new Set<() => void>();

export function publishPlayback(next: PlaybackView | null): void {
    if (current === next) return;
    current = next;
    for (const l of listeners) l();
}

function subscribe(cb: () => void) {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
}

export function usePlaybackView(): PlaybackView | null {
    return useSyncExternalStore(subscribe, () => current);
}
