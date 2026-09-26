import axios from 'axios';
import { useQuery } from '@tanstack/react-query';
import { wallToken } from '../../../lib/wall';
import type { Device, Link, MapDetail } from '../../../types';
import type { MapConfig } from '../../geo/api/geo';

// Dedicated client for the public wallboard (GitHub #15). Unlike the authenticated apiClient it
// sends NO credentials and NO CSRF token - the share token in the path is the only capability,
// and everything it can reach is read-only. Kept separate so a public page can never
// accidentally ride a logged-in operator's session or issue a write.
const wallClient = axios.create({
    headers: { Accept: 'application/json' },
    withCredentials: false,
});

function base(): string {
    const token = wallToken();
    return `/api/public/wall/${token}`;
}

// A wallboard is a passive display: poll on a steady cadence so status and link load stay
// current without a WebSocket (the private broadcast channel needs a session we don't have).
const POLL_MS = 5000;

export const wallKeys = {
    map: ['wall', 'map'] as const,
    devices: ['wall', 'devices'] as const,
    links: ['wall', 'links'] as const,
    mapConfig: ['wall', 'map-config'] as const,
};

/** What a share shows (GitHub #37): the logical diagram, the geo map, or both with a switcher. */
export type WallView = 'logical' | 'geo' | 'both';

type WallMapResponse = { map: MapDetail; view: WallView };

// One poll feeds both hooks below (same key), each picking its half with `select`.
function useWallMapQuery<T>(select: (r: WallMapResponse) => T) {
    return useQuery({
        queryKey: wallKeys.map,
        queryFn: async (): Promise<WallMapResponse> => {
            const { data } = await wallClient.get<{ data: MapDetail; share?: { view?: WallView } }>(`${base()}/map`);
            return { map: data.data, view: data.share?.view ?? 'logical' };
        },
        refetchInterval: POLL_MS,
        select,
    });
}

const pickMap = (r: WallMapResponse) => r.map;
const pickView = (r: WallMapResponse) => r.view;

export function useWallMap() {
    return useWallMapQuery(pickMap);
}

export function useWallView() {
    return useWallMapQuery(pickView);
}

/** Basemap tiles for a geo share. The server 404s it for a logical-only link, so only ask when geo. */
export function useWallMapConfig(enabled: boolean) {
    return useQuery({
        queryKey: wallKeys.mapConfig,
        queryFn: async (): Promise<MapConfig> => {
            const { data } = await wallClient.get<{ data: MapConfig }>(`${base()}/map-config`);
            return data.data;
        },
        enabled,
        staleTime: Infinity,
    });
}

export function useWallDevices() {
    return useQuery({
        queryKey: wallKeys.devices,
        queryFn: async (): Promise<Device[]> => {
            const { data } = await wallClient.get<{ data: Device[] }>(`${base()}/devices`);
            return data.data;
        },
        refetchInterval: POLL_MS,
    });
}

export function useWallLinks() {
    return useQuery({
        queryKey: wallKeys.links,
        queryFn: async (): Promise<Link[]> => {
            const { data } = await wallClient.get<{ data: Link[] }>(`${base()}/links`);
            return data.data;
        },
        refetchInterval: POLL_MS,
    });
}
