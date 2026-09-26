import { useMemo } from 'react';
import { ReactFlowProvider } from '@xyflow/react';
import { GeoFlow } from './GeoFlow';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { useMapDevices } from '../../devices/api/getDevices';
import { useMapLinks } from '../../topology/api/getLinks';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useIsAdmin } from '../../auth/api/auth';
import { useMap } from '../../maps/api/maps';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { useMapConfig } from '../api/geo';
import { usePlayback } from '../hooks/usePlayback';
import { PlaybackBadge, PlaybackBar } from './PlaybackBar';
import { selectDevice, useActiveMapId, useSelectedDeviceId } from '../../../lib/shellStore';

function GeoFlowInner() {
    const activeMapId = useActiveMapId();
    const isAdmin = useIsAdmin();
    const { data: config } = useMapConfig();
    const { data: mapDetail } = useMap(activeMapId);
    const { data: devices } = useMapDevices(activeMapId); // this map's devices only (GitHub #22)
    const { data: links } = useMapLinks(activeMapId);
    const update = useUpdateDevice();
    const selectedDeviceId = useSelectedDeviceId();
    useMapChannel();

    const memberIds = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);
    // History playback (GitHub #22): while it's on, the canvas draws the frame instead of live data.
    const pb = usePlayback(activeMapId, devices, links);
    const nameById = useMemo(() => new Map((devices ?? []).map((d) => [d.id, d.name])), [devices]);

    if (config && !config.enabled) {
        return <div className="grid h-full place-items-center text-sm text-white/50">Geo mode needs a tile URL (MYMATE_MAP_TILE_URL) set.</div>;
    }

    return (
        <div className="relative h-full">
            <GeoFlow
                devices={pb.view.devices}
                links={pb.view.links}
                memberIds={memberIds}
                tileUrl={config?.enabled ? config.tile_url : null}
                attribution={config?.attribution}
                fitKey={activeMapId}
                selectedDeviceId={selectedDeviceId}
                onDeviceClick={selectDevice}
                // Moving a pin is a write, so only an admin gets to drag (the API refuses anyone
                // else), and not while looking at history.
                onDevicesMoved={isAdmin && !pb.active ? (moves) => moves.forEach((m) => update.mutate({ id: m.id, latitude: m.lat, longitude: m.lng })) : undefined}
                emptyHint="Set a location on a device (its editor), then it shows here."
                topLeft={
                    <>
                        <MapSwitcher />
                        <PlaybackBadge pb={pb} />
                    </>
                }
            />
            {pb.active && <PlaybackBar pb={pb} deviceName={(id) => nameById.get(id) ?? `device ${id}`} />}
        </div>
    );
}

/**
 * Geographic mode for a map (GitHub #11): the map's live devices and links fed into GeoFlow, the
 * shared geo canvas the public wallboard draws with too.
 */
export function GeoFlowCanvas() {
    return (
        <ReactFlowProvider>
            <GeoFlowInner />
        </ReactFlowProvider>
    );
}
