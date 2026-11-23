import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { apiClient } from './apiClient';

// laravel-echo\'s reverb connector uses pusher-js under the hood and reads it off window.
(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

// In production the app is served by nginx, which reverse-proxies the Reverb
// WebSocket at /app on the same origin - so connect to whatever FQDN / port /
// scheme the browser is already using (no hard-coded host). In dev (`npm run
// dev`) there is no proxy, so we talk to the Reverb server directly via the
// VITE_REVERB_* values.
const proxied = !import.meta.env.DEV;
const pageIsHttps = window.location.protocol === 'https:';
const pagePort = Number(window.location.port || (pageIsHttps ? 443 : 80));

const wsHost = proxied ? window.location.hostname : import.meta.env.VITE_REVERB_HOST;
const wsPort = proxied ? pagePort : Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const forceTLS = proxied ? pageIsHttps : import.meta.env.VITE_REVERB_SCHEME === 'https';

// The Reverb app key comes from the page (the server's own config), so the socket always uses the
// key Reverb was started with. The build-time VITE value is only a fallback for a page without it
// (`npm run dev` against an older server). A mismatch isn't retried: Reverb answers 4001
// "Application does not exist" and pusher-js gives up, so the map would never update live.
const pageKey = document.querySelector('meta[name="mymate:reverb-key"]')?.getAttribute('content')?.trim();
const appKey = pageKey || import.meta.env.VITE_REVERB_APP_KEY;

// Single app-wide Echo client (Reverb / pusher protocol). Connects lazily on first use.
// The `map` channel is private: we authorise each subscription against
// `/broadcasting/auth` via the credentialed apiClient (session cookie + CSRF).
export const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: appKey,
    wsHost,
    wsPort,
    wssPort: wsPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
        authorize: (
            socketId: string,
            callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void,
        ) => {
            apiClient
                .post<{ auth: string; channel_data?: string }>(
                    '/broadcasting/auth',
                    { socket_id: socketId, channel_name: channel.name },
                    { baseURL: '/' },
                )
                .then((res) => callback(null, res.data))
                .catch((err: unknown) => callback(err instanceof Error ? err : new Error('Broadcast auth failed'), null));
        },
    }),
});
