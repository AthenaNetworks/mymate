import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient, authUserKey } from '../../../lib/apiClient';
import { createPasskey, getPasskey } from '../lib/passkey';

export interface Passkey {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
}

const passkeysKey = ['passkeys'] as const;

/** This operator's registered passkeys. */
export function usePasskeys() {
    return useQuery({
        queryKey: passkeysKey,
        queryFn: async (): Promise<Passkey[]> => (await apiClient.get<Passkey[]>('/passkeys')).data,
    });
}

/** Enrol a new passkey: fetch options -> browser ceremony -> store. Also satisfies the 2FA gate. */
export function useRegisterPasskey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (name: string): Promise<void> => {
            const { data: opts } = await apiClient.post<{ options: unknown }>('/passkeys/register/options');
            const credential = await createPasskey(opts.options);
            await apiClient.post('/passkeys/register', { name, credential });
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: passkeysKey });
            qc.invalidateQueries({ queryKey: authUserKey }); // stage -> verified
        },
    });
}

/** The 2FA step: fetch options -> browser ceremony -> verify. Marks the session passkey-verified. */
export function useVerifyPasskey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<void> => {
            const { data: opts } = await apiClient.post<{ options: unknown }>('/passkeys/verify/options');
            const credential = await getPasskey(opts.options);
            await apiClient.post('/passkeys/verify', { credential });
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: authUserKey }),
    });
}

export function useDeletePasskey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/passkeys/${id}`);
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: passkeysKey }),
    });
}

// --- Admin: the mandatory-passkey policy ---------------------------------

export interface SecuritySettings {
    passkey_required: boolean;
    affected_operators?: number;
}

export function useSecuritySettings() {
    return useQuery({
        queryKey: ['security-settings'],
        queryFn: async (): Promise<SecuritySettings> => (await apiClient.get<{ data: SecuritySettings }>('/settings/security')).data.data,
    });
}

export function useUpdateSecuritySettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (passkeyRequired: boolean): Promise<SecuritySettings> =>
            (await apiClient.put<{ data: SecuritySettings }>('/settings/security', { passkey_required: passkeyRequired })).data.data,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['security-settings'] });
            qc.invalidateQueries({ queryKey: authUserKey });
        },
    });
}
