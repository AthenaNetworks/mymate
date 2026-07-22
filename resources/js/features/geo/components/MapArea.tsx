import { ReactFlowProvider } from '@xyflow/react';
import { MapCanvas } from '../../topology/components/MapCanvas';
import { MapLeafletCanvas } from './MapLeafletCanvas';
import { useMap } from '../../maps/api/maps';
import { useActiveMapId } from '../../../lib/shellStore';

/**
 * The map view's canvas: the free-form React Flow diagram, or - when the active map has geo mode
 * turned on - a Leaflet basemap of its devices (GitHub #11). The inspector works with both.
 */
export function MapArea() {
    const activeMapId = useActiveMapId();
    const { data: mapDetail } = useMap(activeMapId);

    if (mapDetail?.leaflet_enabled) {
        return <MapLeafletCanvas />;
    }
    return (
        <ReactFlowProvider>
            <MapCanvas />
        </ReactFlowProvider>
    );
}
