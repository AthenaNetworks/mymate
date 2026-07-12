import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient, authUserKey, fetchCsrfCookie } from '../../../lib/apiClient';
import type { AuthUser } from '../../../types';

export interface Credentials {
    email: string;
    password: string;
    remember?: boolean;
}

/**
 * The current operator, or null when signed out. A 401 (handled by the apiClient
 * interceptor) resolves to null rather than an error, so the app shows the login
 * screen instead of an error state.
 */
export function useCurrentUser() {
    return useQuery({
        queryKey: authUserKey,
        queryFn: async (): Promise<AuthUser | null> => {
            try {
                const { data } = await apiClient.get<AuthUser>('/user');
                return data;
            } catch (e) {
                if ((e as { response?: { status?: number } })?.response?.status === 401) return null;
                throw e;
            }
        },
        retry: false,
        staleTime: Infinity,
    });
}

/**
 * Whether the current operator may change things. Non-admins are read-only -
 * the backend enforces this on every write endpoint (`RestrictWritesToAdmins`); this hook
 * lets the UI hide the write controls so a viewer never sees a button that would 403.
 */
export function useIsAdmin(): boolean {
    const { data: user } = useCurrentUser();
    return user?.is_admin ?? false;
}

export function useLogin() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (credentials: Credentials): Promise<AuthUser> => {
            await fetchCsrfCookie(); // prime the XSRF-TOKEN cookie
            const { data } = await apiClient.post<AuthUser>('/login', credentials, { baseURL: '/' });
            return data;
        },
        onSuccess: (user) => qc.setQueryData(authUserKey, user),
    });
}

export interface UpdatePasswordInput {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export function useUpdatePassword() {
    return useMutation({
        mutationFn: async (input: UpdatePasswordInput): Promise<void> => {
            await apiClient.put('/account/password', input);
        },
    });
}

export function useLogout() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<void> => {
            await apiClient.post('/logout', {}, { baseURL: '/' });
        },
        // Clear the user + all cached data so nothing leaks across a sign-out.
        onSettled: () => {
            qc.setQueryData(authUserKey, null);
            qc.clear();
        },
    });
}
