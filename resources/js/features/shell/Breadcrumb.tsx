import { displayName } from '../../lib/deviceName';
import { setView, useView } from '../../lib/shellStore';
import { useDevice } from '../devices/api/getDevices';
import { parseDeviceRoute, useLocationHref } from '../device-page/lib/location';
import { viewLabel } from './NavRail';

/**
 * Where you are, in the top bar: the page, then the device on a device page (whose "Devices"
 * goes back to the list).
 */
export function Breadcrumb() {
    const view = useView();
    const href = useLocationHref();
    const deviceId = view === 'device' ? parseDeviceRoute(href).deviceId : null;
    const { data: device } = useDevice(deviceId);

    const label = viewLabel(view);
    // On the map and geo views the open map's name is already in the map's own toolbar (the
    // switcher), so only the page is named here.
    const detail = view === 'device' && device ? displayName(device) : undefined;
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
