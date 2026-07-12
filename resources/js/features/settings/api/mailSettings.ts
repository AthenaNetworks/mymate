import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type MailEncryption = 'tls' | 'ssl' | 'none';

/** API-safe view - never carries the password (only whether one is stored). */
export interface MailSettingsView {
    host: string;
    port: number;
    encryption: MailEncryption;
    username: string;
    from_address: string;
    from_name: string;
    password_set: boolean;
    configured: boolean;
}

export interface MailSettingsInput {
    host: string;
    port: number;
    encryption: MailEncryption;
    username?: string;
    password?: string; // blank = keep the stored one
    from_address: string;
    from_name?: string;
}

const mailKey = ['mail-settings'] as const;

export function useMailSettings() {
    return useQuery({
        queryKey: mailKey,
        queryFn: async (): Promise<MailSettingsView> => {
            const { data } = await apiClient.get<{ data: MailSettingsView }>('/settings/mail');
            return data.data;
        },
    });
}

export function useUpdateMailSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: MailSettingsInput): Promise<MailSettingsView> => {
            const { data } = await apiClient.put<{ data: MailSettingsView }>('/settings/mail', input);
            return data.data;
        },
        onSuccess: (data) => qc.setQueryData(mailKey, data),
    });
}

export function useTestMail() {
    return useMutation({
        mutationFn: async (to: string): Promise<{ ok: boolean }> => {
            const { data } = await apiClient.post<{ ok: boolean }>('/settings/mail/test', { to });
            return data;
        },
    });
}
