import { useMemo, useState } from 'react';
import { ReactFlowProvider } from '@xyflow/react';
import { GeoFlow, type GeoNoteHandlers } from './GeoFlow';
import { LinkBinderDialog, type PendingLink } from '../../topology/components/LinkBinderDialog';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { useMapDevices } from '../../devices/api/getDevices';
import { useMapLinks } from '../../topology/api/getLinks';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useIsAdmin } from '../../auth/api/auth';
import { useCreateMapNote, useDeleteMapNote, useMap, useUpdateMapNote } from '../../maps/api/maps';
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

    // Editing on the geo map (GitHub #52): admins only, and never while looking at history.
    const editable = isAdmin && !pb.active && activeMapId !== null;
    const [pending, setPending] = useState<PendingLink | null>(null);
    const createNote = useCreateMapNote();
    const updateNote = useUpdateMapNote();
    const deleteNote = useDeleteMapNote();
    const noteHandlers = useMemo<GeoNoteHandlers | undefined>(() => {
        if (!editable || activeMapId === null) return undefined;
        const mapId = activeMapId;
        return {
            onAdd: (latitude, longitude) => createNote.mutate({ mapId, text: 'New label', latitude, longitude }),
            onMove: (noteId, latitude, longitude) => updateNote.mutate({ mapId, noteId, latitude, longitude }),
            onSave: (noteId, text) => updateNote.mutate({ mapId, noteId, text }),
            onStyle: (noteId, patch) => updateNote.mutate({ mapId, noteId, ...patch }),
            onRemove: (noteId) => deleteNote.mutate({ mapId, noteId }),
        };
        // The mutation objects change identity every render; their mutate is stable enough here.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editable, activeMapId]);
    const unplaced = useMemo(
        () => (devices ?? []).filter((d) => memberIds.has(d.id) && (d.geo_latitude == null || d.geo_longitude == null)),
        [devices, memberIds],
    );

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
                emptyHint={editable ? 'Use "not pinned" at the bottom left to drop devices on the map, or set a location in the device editor.' : 'Set a location on a device (its editor), then it shows here.'}
                notes={mapDetail?.map_notes}
                noteHandlers={noteHandlers}
                onConnectDevices={editable ? ({ a, b, aHandle, bHandle }) => setPending({ aDeviceId: a, bDeviceId: b, aHandle, bHandle }) : undefined}
                unplaced={editable ? unplaced : undefined}
                onPlaceDevice={editable ? (id, latitude, longitude) => update.mutate({ id, latitude, longitude }) : undefined}
                topLeft={
                    <>
                        <MapSwitcher />
                        <PlaybackBadge pb={pb} />
                    </>
                }
            />
            {pb.active && <PlaybackBar pb={pb} deviceName={(id) => nameById.get(id) ?? `device ${id}`} />}
            {pending && devices && <LinkBinderDialog pending={pending} devices={devices} onClose={() => setPending(null)} />}
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
