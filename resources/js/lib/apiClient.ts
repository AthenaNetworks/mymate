import axios from 'axios';
import { queryClient } from './queryClient';

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

// On any 401, drop the cached user so the app falls back to the login screen.
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            queryClient.setQueryData(authUserKey, null);
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
