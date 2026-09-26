import { useEffect } from 'react';
import { apiClient } from '../../../lib/apiClient';

// The server holds a watch for ~150 s (App\Support\LiveWatch::TTL), so a ping a minute keeps it
// alive with room for one to go missing.
const HEARTBEAT_MS = 60_000;

/**
 * Tell the server this device is open on screen. The live util stream normally carries only link
 * ends (all the map draws); while a device is watched it carries every port of it, so the port
 * lists tick live too. Stops by itself shortly after the page or inspector closes.
 */
export function useWatchDevice(deviceId: number | null) {
    useEffect(() => {
        if (deviceId === null) return;
        const ping = () => {
            // best-effort: a failed ping just means the unlinked ports update on the next refetch
            apiClient.post(`/devices/${deviceId}/live`).catch(() => {});
        };
        ping();
        const t = setInterval(ping, HEARTBEAT_MS);
        return () => clearInterval(t);
    }, [deviceId]);
}
