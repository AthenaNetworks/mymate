import { useMemo } from 'react';
import { GeoFlow } from '../../geo/components/GeoFlow';
import { useWallDevices, useWallLinks, useWallMap, useWallMapConfig } from '../api/wall';

/**
 * The geo view of a shared wallboard (GitHub #37). The same GeoFlow canvas the authenticated geo
 * mode uses, fed from the token-gated public endpoints, with no drag, no inspector and no edit.
 */
export function WallGeoCanvas() {
    const { data: mapDetail } = useWallMap();
    const { data: devices } = useWallDevices();
    const { data: links } = useWallLinks();
    const { data: config } = useWallMapConfig(true);

    const memberIds = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);

    if (config && !config.enabled) {
        return <div className="grid h-full place-items-center text-sm text-white/50">The geographic view isn't set up on this server.</div>;
    }

    return (
        <GeoFlow
            devices={devices}
            links={links}
            memberIds={memberIds}
            tileUrl={config?.enabled ? config.tile_url : null}
            attribution={config?.attribution}
            fitKey={mapDetail?.id ?? null}
            emptyHint="Devices show up here once they have a location."
        />
    );
}
