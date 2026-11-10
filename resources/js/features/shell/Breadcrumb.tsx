import { setView, useActiveMapId, useView } from '../../lib/shellStore';
import { useMaps } from '../maps/api/maps';
import { useDevice } from '../devices/api/getDevices';
import { parseDeviceRoute, useLocationHref } from '../device-page/lib/location';
import { viewLabel } from './NavRail';

/**
 * Where you are, in the top bar: the page, then what it's showing - the open map on the map
 * and geo views, the device on a device page (whose "Devices" goes back to the list).
 */
export function Breadcrumb() {
    const view = useView();
    const href = useLocationHref();
    const activeMapId = useActiveMapId();
    const onMap = view === 'map' || view === 'geo';
    const { data: maps } = useMaps();
    const deviceId = view === 'device' ? parseDeviceRoute(href).deviceId : null;
    const { data: device } = useDevice(deviceId);

    const label = viewLabel(view);
    const detail = onMap ? maps?.find((m) => m.id === activeMapId)?.name : view === 'device' ? device?.name : undefined;
    if (!label) return null;

    return (
        <nav aria-label="Breadcrumb" className="hidden min-w-0 items-center gap-1.5 text-xs text-white/40 sm:flex">
            {view === 'device' ? (
                <button onClick={() => setView('devices')} className="shrink-0 transition-colors hover:text-white/80">
                    {label}
                </button>
            ) : (
                <span className={detail ? 'shrink-0' : 'shrink-0 text-white/70'}>{label}</span>
            )}
            {detail && (
                <>
                    <span className="text-white/20">/</span>
                    <span className="max-w-[16rem] truncate text-white/70" title={detail}>
                        {detail}
                    </span>
                </>
            )}
        </nav>
    );
}
