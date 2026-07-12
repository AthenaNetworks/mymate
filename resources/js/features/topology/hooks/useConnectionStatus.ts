import { useEffect, useState } from 'react';
import { echo } from '../../../lib/echo';

export type ConnState = 'connected' | 'connecting' | 'offline';

// The underlying pusher-js connection (Reverb speaks the pusher protocol).
function pusherConnection(): { state?: string; bind: Function; unbind: Function } | undefined {
    return (echo.connector as unknown as { pusher?: { connection?: any } }).pusher?.connection;
}

function classify(state: string | undefined): ConnState {
    if (state === 'connected') return 'connected';
    if (state === 'connecting' || state === 'initialized' || state === 'unavailable') return 'connecting';
    return 'offline'; // disconnected / failed
}

/**
 * Live WebSocket (Reverb) connection state for the resilience indicator (NFR-7).
 * On reconnect, useMapChannel already refetches the device + link snapshot.
 */
export function useConnectionStatus(): ConnState {
    const [state, setState] = useState<ConnState>(() => classify(pusherConnection()?.state));

    useEffect(() => {
        const conn = pusherConnection();
        if (!conn) return;

        setState(classify(conn.state));
        const handler = (states: { current: string }) => setState(classify(states.current));
        conn.bind('state_change', handler);

        return () => conn.unbind('state_change', handler);
    }, []);

    return state;
}
