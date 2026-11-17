import { useEffect } from 'react';
import { mapIdFromPath, mapPath, takeRequestedMapId, useActiveMapId, useView } from '../../../lib/shellStore';
import { pushToast } from '../../../lib/toast';
import { useLocationHref } from '../../device-page/lib/location';
import { useMaps } from '../api/maps';

/**
 * Keeps the map view's URL on the open map, /maps/{id}-{slug}, so it can be bookmarked and
 * shared. The store reads the id back on load and on Back/Forward (shellStore MAP_PAGE); this
 * hook writes it, since only it knows the map names.
 *
 * - Switching to another map adds a history entry, so Back returns to the previous map.
 * - Everything else replaces the entry: adding the name to a bare /maps/{id} or /, correcting
 *   the name after a rename, or leaving a map that no longer exists.
 * - A map a URL asked for that's deleted or not visible to this user gets a toast; MapSwitcher
 *   has already fallen back to the default map.
 *
 * Mounted once, in the app shell. Only the map view is touched: the geo view shares the active
 * map but keeps its own /geo URL.
 */
export function useMapRoute(): void {
    const view = useView();
    const activeMapId = useActiveMapId();
    const href = useLocationHref(); // re-runs on Back/Forward and in-app navigation
    const { data: maps } = useMaps();

    useEffect(() => {
        if (!maps) return;
        const requested = takeRequestedMapId();
        if (requested !== null && !maps.some((m) => m.id === requested)) {
            pushToast({
                title: 'That map isn\'t available',
                detail: 'It may have been deleted, or you don\'t have access to it. Showing the default map instead.',
                tone: 'info',
                key: 'map-route-missing',
            });
        }
    }, [maps, href]);

    useEffect(() => {
        if (view !== 'map' || !maps) return;
        const active = maps.find((m) => m.id === activeMapId);
        if (!active) return; // MapSwitcher is picking the default map; this runs again when it has

        const { pathname, search, hash } = window.location;
        const target = mapPath(active.id, active.name);
        if (pathname === target) return;

        const shownId = mapIdFromPath(pathname);
        const switched = shownId !== null && shownId !== active.id && maps.some((m) => m.id === shownId);
        if (switched) window.history.pushState({ view: 'map' }, '', target + search + hash);
        else window.history.replaceState({ view: 'map' }, '', target + search + hash);
    }, [view, activeMapId, maps, href]);
}
