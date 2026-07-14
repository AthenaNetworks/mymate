import { useMutation } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type LibreNmsDriver = 'mysql' | 'api';

export interface LibreNmsConnection {
    driver: LibreNmsDriver;
    // mysql
    host?: string;
    port?: number;
    database?: string;
    username?: string;
    password?: string;
    // api
    base_url?: string;
    token?: string;
}

export interface LibreNmsDevice {
    hostname: string;
    ip: string | null;
    snmp_community: string | null;
    os: string | null;
    hardware: string | null;
    sysname: string | null;
    disabled: boolean;
}

export interface LibreNmsPreview {
    driver: LibreNmsDriver;
    device_count: number;
    with_community: number;
    devices: LibreNmsDevice[];
    maps: { name: string; node_count: number }[];
}

export interface LibreNmsImportOptions {
    import_credentials?: boolean;
    import_maps?: boolean;
    include_disabled?: boolean;
    credential_id?: number | null;
    only?: string[];
}

export interface LibreNmsSummary {
    devices: { created: number; updated: number; skipped: number };
    credentials: number;
    maps: number;
    positions: number;
}

export function useLibreNmsPreview() {
    return useMutation({
        mutationFn: async (conn: LibreNmsConnection): Promise<LibreNmsPreview> => {
            const { data } = await apiClient.post<{ data: LibreNmsPreview }>('/imports/librenms/preview', conn);
            return data.data;
        },
    });
}

export function useLibreNmsImport() {
    return useMutation({
        mutationFn: async (input: LibreNmsConnection & LibreNmsImportOptions): Promise<LibreNmsSummary> => {
            const { data } = await apiClient.post<{ data: LibreNmsSummary }>('/imports/librenms', input);
            return data.data;
        },
    });
}
