import { ReactFlowProvider } from '@xyflow/react';
import { MapCanvas } from '../../topology/components/MapCanvas';
import { GeoFlowCanvas } from './GeoFlowCanvas';
import { useMap } from '../../maps/api/maps';
import { useActiveMapId } from '../../../lib/shellStore';

/**
 * The map view's canvas: the free-form React Flow diagram, or - when the active map has geo mode
 * turned on - the same native nodes/links laid out on a map basemap by their coordinates
 * (GitHub #11). The inspector works with both.
 */
export function MapArea() {
    const activeMapId = useActiveMapId();
    const { data: mapDetail } = useMap(activeMapId);

    if (mapDetail?.leaflet_enabled) {
        return <GeoFlowCanvas />;
    }
    return (
        <ReactFlowProvider>
            <MapCanvas />
        </ReactFlowProvider>
    );
}
