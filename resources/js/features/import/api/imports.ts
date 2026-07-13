import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type ImportStatus = 'pending' | 'extracting' | 'importing' | 'completed' | 'failed' | 'cancelled';
export type ImportMode = 'upsert' | 'fresh';

export type ImportProgress = {
    percent: number | null;
    detail: string | null;
    eta_seconds: number | null;
};

export type ImportRun = {
    id: number;
    original_filename: string;
    mode: ImportMode;
    include_history: boolean;
    extract_timeout: number | null;
    status: ImportStatus;
    stage: string | null;
    progress: ImportProgress | null;
    cancel_requested: boolean;
    summary: Record<string, unknown> | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
};

export const importKeys = {
    all: ['imports'] as const,
    one: (id: number) => ['imports', id] as const,
};

const TERMINAL: ImportStatus[] = ['completed', 'failed', 'cancelled'];
export const isTerminal = (s: ImportStatus): boolean => TERMINAL.includes(s);

/** Recent import runs (newest first). */
export function useImports() {
    return useQuery({
        queryKey: importKeys.all,
        queryFn: async (): Promise<ImportRun[]> => {
            const { data } = await apiClient.get<{ data: ImportRun[] }>('/imports');
            return data.data;
        },
    });
}

/** Poll one run while it's in flight; stop polling once terminal. */
export function useImportRun(id: number | null) {
    return useQuery({
        queryKey: id ? importKeys.one(id) : ['imports', 'none'],
        enabled: id !== null,
        queryFn: async (): Promise<ImportRun> => {
            const { data } = await apiClient.get<{ data: ImportRun }>(`/imports/${id}`);
            return data.data;
        },
        refetchInterval: (query) => {
            const status = query.state.data?.status;
            return status && isTerminal(status) ? false : 1500;
        },
    });
}

/** Live upload progress (the POST body transfer, before the server-side import job). */
export type UploadProgress = { percent: number; bytesPerSec: number; loaded: number; total: number };

export type UploadInput = {
    file: File;
    mode: ImportMode;
    includeHistory: boolean;
    /** Extraction time limit in seconds; omit/undefined to use the server default. */
    extractTimeout?: number;
    onProgress?: (p: UploadProgress) => void;
};

export function useUploadImport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ file, mode, includeHistory, extractTimeout, onProgress }: UploadInput): Promise<ImportRun> => {
            const form = new FormData();
            form.append('database', file);
            form.append('mode', mode);
            form.append('include_history', includeHistory ? '1' : '0');
            if (extractTimeout) form.append('extract_timeout', String(extractTimeout));
            const { data } = await apiClient.post<{ data: ImportRun }>('/imports', form, {
                onUploadProgress: (e) => {
                    if (!onProgress) return;
                    const total = e.total ?? file.size;
                    const loaded = e.loaded ?? 0;
                    onProgress({
                        percent: total > 0 ? Math.round((loaded / total) * 100) : 0,
                        bytesPerSec: e.rate ?? 0, // axios v1 provides a smoothed rate (bytes/sec)
                        loaded,
                        total,
                    });
                },
            });
            return data.data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: importKeys.all }),
    });
}

export function useCancelImport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<ImportRun> => {
            const { data } = await apiClient.post<{ data: ImportRun }>(`/imports/${id}/cancel`);
            return data.data;
        },
        onSuccess: (run) => {
            qc.invalidateQueries({ queryKey: importKeys.all });
            qc.invalidateQueries({ queryKey: importKeys.one(run.id) });
        },
    });
}
