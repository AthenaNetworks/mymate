import axios from 'axios';
import { queryClient } from './queryClient';
import { pushToast } from './toast';

// Shared HTTP client for the JSON API (same-origin, served by Laravel). :
// Sanctum SPA cookie auth - send credentials so the session cookie rides along, and
// axios mirrors the XSRF-TOKEN cookie into the X-XSRF-TOKEN header for CSRF.
export const apiClient = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json' },
    withCredentials: true,
    withXSRFToken: true,
});

/** The auth/current-user query key (kept here so the 401 interceptor can clear it). */
export const authUserKey = ['auth', 'user'] as const;

// Endpoints that are *part of* deciding who's logged in - their errors must reject normally so
// useCurrentUser can resolve to null and the login form can show its own message.
const AUTH_ENDPOINTS = ['/user', '/login', '/logout', '/sanctum/csrf-cookie'];
const isAuthEndpoint = (url?: string) => !!url && AUTH_ENDPOINTS.some((e) => url.includes(e));

// Debounce the "session ended" notice - one 401 usually comes with a burst of them.
let lastExpiredNotice = 0;

/**
 * Turn a dead session into a clean bounce to the login screen instead of a wall of red errors.
 * On 401 we drop the cached user (which re-renders the app to <LoginScreen/>), and for any
 * ordinary endpoint we *swallow* the rejection - returning a promise that never settles - so the
 * ~20 per-mutation onError toasts scattered across the app don't fire on top. A single info toast
 * explains why. A 423 (passkey step required) is handled the same way: refetch the user so the gate
 * shows the enrol/2FA screen, and swallow so nothing red flashes.
 */
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        const url = error?.config?.url as string | undefined;

        if (status === 401) {
            queryClient.setQueryData(authUserKey, null);
            if (!isAuthEndpoint(url)) {
                if (Date.now() - lastExpiredNotice > 3000) {
                    lastExpiredNotice = Date.now();
                    pushToast({ title: 'Your session ended', detail: 'Please sign in again.', tone: 'info' });
                }
                return new Promise(() => {}); // never settles - the app is already showing login
            }
        }

        if (status === 423 && error?.response?.data?.code === 'passkey_required' && !isAuthEndpoint(url)) {
            queryClient.invalidateQueries({ queryKey: authUserKey }); // re-fetch -> gate shows the passkey step
            return new Promise(() => {});
        }

        return Promise.reject(error);
    },
);

/**
 * Fetch the CSRF cookie before a state-changing auth call (login/logout). It lives
 * at the app root, not under /api.
 */
export function fetchCsrfCookie(): Promise<unknown> {
    return apiClient.get('/sanctum/csrf-cookie', { baseURL: '/' });
}
