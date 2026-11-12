import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type SsoProvider = 'entra' | 'oidc';

/** API-safe view - never carries the client secret, only whether one is stored. */
export interface SsoSettingsView {
    enabled: boolean;
    provider: SsoProvider;
    tenant: string; // Entra: tenant id or domain
    issuer: string; // generic OIDC: issuer URL
    client_id: string;
    client_secret_set: boolean;
    role_claim: string;
    admin_role: string;
    operator_role: string;
    configured: boolean;
    callback_url: string; // the redirect URI to register at the provider
}

export interface SsoSettingsInput {
    enabled: boolean;
    provider: SsoProvider;
    tenant: string;
    issuer: string;
    client_id: string;
    client_secret?: string | null; // null/omitted = keep the stored one
    role_claim: string;
    admin_role: string;
    operator_role: string;
}

export type SsoTestResult = { ok: true; issuer: string; authorization_endpoint: string } | { ok: false; reason: string; message: string };

const ssoKey = ['sso-settings'] as const;

export function useSsoSettings() {
    return useQuery({
        queryKey: ssoKey,
        queryFn: async (): Promise<SsoSettingsView> => {
            const { data } = await apiClient.get<{ data: SsoSettingsView }>('/settings/sso');
            return data.data;
        },
    });
}

export function useUpdateSsoSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: SsoSettingsInput): Promise<SsoSettingsView> => {
            const { data } = await apiClient.put<{ data: SsoSettingsView }>('/settings/sso', input);
            return data.data;
        },
        onSuccess: (view) => qc.setQueryData(ssoKey, view),
    });
}

/** Fetch the provider's discovery document with the saved settings. */
export function useTestSso() {
    return useMutation({
        mutationFn: async (): Promise<SsoTestResult> => {
            try {
                const { data } = await apiClient.post<SsoTestResult>('/settings/sso/test');
                return data;
            } catch (e) {
                const body = (e as { response?: { data?: SsoTestResult } }).response?.data;
                if (body && body.ok === false) return body;
                throw e;
            }
        },
    });
}
