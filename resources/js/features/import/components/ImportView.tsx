import { useRef, useState } from 'react';
import { DownloadSimple, Database, Warning, X, CheckCircle, Spinner, Prohibit } from '@phosphor-icons/react';
import {
    useImports,
    useImportRun,
    useUploadImport,
    useCancelImport,
    isTerminal,
    type ImportMode,
    type ImportRun,
    type UploadProgress,
} from '../api/imports';
import { pushToast } from '../../../lib/toast';

const card = 'rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';

const MB = 1_048_576;
const fmtMB = (bytes: number) => `${(bytes / MB).toFixed(1)} MB`;
const fmtSpeed = (bytesPerSec: number) => (bytesPerSec > 0 ? `${(bytesPerSec / MB).toFixed(1)} MB/s` : '...');

function fmtEta(seconds: number | null): string {
    if (seconds === null || seconds < 0) return '-';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m < 60 ? `${m}m ${s}s` : `${Math.floor(m / 60)}h ${m % 60}m`;
}

/** Flatten the summary JSON into readable "label: detail" metric chips. */
function summaryMetrics(summary: Record<string, unknown> | null): { label: string; value: string }[] {
    if (!summary) return [];
    const out: { label: string; value: string }[] = [];
    for (const [key, val] of Object.entries(summary)) {
        if (val === null || val === undefined) continue;
        if (typeof val === 'object') {
            const parts = Object.entries(val as Record<string, unknown>)
                .map(([k, v]) => `${v} ${k}`)
                .join(', ');
            out.push({ label: key, value: parts });
        } else {
            out.push({ label: key.replace(/_/g, ' '), value: String(val) });
        }
    }
    return out;
}

const STATUS_TONE: Record<string, string> = {
    completed: 'text-emerald-300',
    failed: 'text-rose-300',
    cancelled: 'text-amber-300',
    importing: 'text-sky-300',
    extracting: 'text-sky-300',
    pending: 'text-white/50',
};

function ActiveRun({ runId }: { runId: number }) {
    const { data: run } = useImportRun(runId);
    const cancel = useCancelImport();
    if (!run) return null;

    const percent = run.progress?.percent ?? 0;
    const running = !isTerminal(run.status);
    const metrics = summaryMetrics(run.summary);

    return (
        <section className={card}>
            <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    {running ? (
                        <Spinner weight="bold" className="h-4 w-4 animate-spin text-sky-300" />
                    ) : run.status === 'completed' ? (
                        <CheckCircle weight="fill" className="h-4 w-4 text-emerald-300" />
                    ) : run.status === 'cancelled' ? (
                        <Prohibit weight="fill" className="h-4 w-4 text-amber-300" />
                    ) : (
                        <Warning weight="fill" className="h-4 w-4 text-rose-300" />
                    )}
                    <h2 className="text-sm font-bold text-white">{run.original_filename}</h2>
                    <span className={`text-xs font-semibold uppercase tracking-wide ${STATUS_TONE[run.status] ?? ''}`}>
                        {run.status}
                    </span>
                </div>
                {running && (
                    <button
                        onClick={() =>
                            cancel.mutate(run.id, {
                                onSuccess: () => pushToast({ title: 'Cancellation requested', tone: 'info' }),
                            })
                        }
                        disabled={run.cancel_requested || cancel.isPending}
                        className="flex items-center gap-1.5 rounded-lg bg-rose-500/15 px-3 py-1.5 text-xs font-semibold text-rose-200 ring-1 ring-rose-400/20 transition hover:bg-rose-500/25 disabled:opacity-50"
                    >
                        <X weight="bold" className="h-3.5 w-3.5" />
                        {run.cancel_requested ? 'Stopping...' : 'Cancel'}
                    </button>
                )}
            </div>

            {/* Progress bar */}
            <div className="mb-2 h-2 w-full overflow-hidden rounded-full bg-white/[0.06]">
                <div
                    className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-sky-400 transition-all duration-500 ease-fluid"
                    style={{ width: `${Math.max(2, Math.min(100, percent))}%` }}
                />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-white/55">
                <span className="font-medium text-white/80">{run.stage ?? '...'}</span>
                <span className="tabular-nums">
                    {percent.toFixed(0)}%{run.progress?.detail ? ` - ${run.progress.detail}` : ''}
                    {running && run.progress?.eta_seconds != null ? ` - ~${fmtEta(run.progress.eta_seconds)} left` : ''}
                </span>
            </div>

            {run.error && (
                <p className="mt-3 rounded-lg bg-rose-500/10 p-3 text-xs text-rose-200 ring-1 ring-rose-400/20">{run.error}</p>
            )}

            {metrics.length > 0 && (
                <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {metrics.map((m) => (
                        <div key={m.label} className="rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/[0.06]">
                            <div className="text-[10px] uppercase tracking-wide text-white/40">{m.label}</div>
                            <div className="text-xs font-medium text-white/85">{m.value}</div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

export function ImportView() {
    const [file, setFile] = useState<File | null>(null);
    const [mode, setMode] = useState<ImportMode>('upsert');
    const [includeHistory, setIncludeHistory] = useState(true);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [uploadProg, setUploadProg] = useState<UploadProgress | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const upload = useUploadImport();
    const { data: runs } = useImports();

    function start() {
        if (!file) return;
        setUploadProg({ percent: 0, bytesPerSec: 0, loaded: 0, total: file.size });
        upload.mutate(
            { file, mode, includeHistory, onProgress: setUploadProg },
            {
                onSuccess: (run: ImportRun) => {
                    setActiveId(run.id);
                    setUploadProg(null);
                    setFile(null);
                    if (fileInput.current) fileInput.current.value = '';
                    pushToast({ title: 'Import started', detail: run.original_filename, tone: 'info' });
                },
                onError: (e: unknown) => {
                    setUploadProg(null);
                    const msg =
                        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                        'Upload failed';
                    pushToast({ title: 'Import failed to start', detail: msg, tone: 'down' });
                },
            },
        );
    }

    return (
        <div className="h-full overflow-y-auto p-6">
            <div className="mx-auto max-w-3xl space-y-5">
                <header>
                    <h1 className="flex items-center gap-2 text-lg font-bold text-white">
                        <DownloadSimple weight="duotone" className="h-5 w-5 text-emerald-300" />
                        Import from The Dude
                    </h1>
                    <p className="mt-1 text-sm text-white/45">
                        Upload a MikroTik <span className="font-mono text-white/70">dude.db</span> to import its devices,
                        interfaces, links, maps and history into My Mate.
                    </p>
                </header>

                {/* Upload form */}
                <section className={card}>
                    <label
                        className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-white/15 bg-white/[0.02] px-4 py-8 text-center transition hover:border-emerald-400/40 hover:bg-white/[0.04]"
                    >
                        <Database weight="duotone" className="h-8 w-8 text-white/40" />
                        <span className="text-sm text-white/70">
                            {file ? file.name : 'Choose a dude.db file or drop it here'}
                        </span>
                        {file && <span className="text-xs text-white/40">{(file.size / 1_048_576).toFixed(1)} MB</span>}
                        <input
                            ref={fileInput}
                            type="file"
                            accept=".db,application/x-sqlite3,application/vnd.sqlite3"
                            className="hidden"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                    </label>

                    {/* Mode */}
                    <div className="mt-4 grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={() => setMode('upsert')}
                            className={`rounded-xl px-4 py-3 text-left ring-1 transition ${
                                mode === 'upsert'
                                    ? 'bg-emerald-500/10 ring-emerald-400/40'
                                    : 'bg-white/[0.02] ring-white/10 hover:bg-white/[0.04]'
                            }`}
                        >
                            <div className="text-sm font-semibold text-white">Merge (upsert)</div>
                            <div className="mt-0.5 text-xs text-white/50">
                                Keep existing config; update or add matching devices, interfaces &amp; links.
                            </div>
                        </button>
                        <button
                            type="button"
                            onClick={() => setMode('fresh')}
                            className={`rounded-xl px-4 py-3 text-left ring-1 transition ${
                                mode === 'fresh'
                                    ? 'bg-amber-500/10 ring-amber-400/40'
                                    : 'bg-white/[0.02] ring-white/10 hover:bg-white/[0.04]'
                            }`}
                        >
                            <div className="text-sm font-semibold text-white">Start fresh (replace)</div>
                            <div className="mt-0.5 text-xs text-white/50">
                                Wipe existing devices, interfaces, links, maps &amp; history first.
                            </div>
                        </button>
                    </div>

                    {mode === 'fresh' && (
                        <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-200 ring-1 ring-amber-400/20">
                            <Warning weight="fill" className="mt-0.5 h-4 w-4 shrink-0" />
                            This permanently deletes all current devices, interfaces, links, maps and history before
                            importing. Users, settings and alert config are kept.
                        </p>
                    )}

                    <label className="mt-4 flex items-center gap-2 text-sm text-white/70">
                        <input
                            type="checkbox"
                            checked={includeHistory}
                            onChange={(e) => setIncludeHistory(e.target.checked)}
                            className="h-4 w-4 rounded border-white/20 bg-white/5 text-emerald-500"
                        />
                        Import chart history (slower - large databases can take several minutes)
                    </label>

                    <button
                        onClick={start}
                        disabled={!file || upload.isPending}
                        className="mt-5 w-full rounded-xl bg-emerald-500/90 px-4 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {upload.isPending ? 'Uploading...' : 'Start import'}
                    </button>

                    {/* Live upload transfer (the POST body) - before the server-side import job. */}
                    {uploadProg && (
                        <div className="mt-4">
                            <div className="mb-1.5 h-2 w-full overflow-hidden rounded-full bg-white/[0.06]">
                                <div
                                    className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-sky-400 transition-all duration-200"
                                    style={{ width: `${Math.max(2, uploadProg.percent)}%` }}
                                />
                            </div>
                            <div className="flex items-center justify-between text-xs text-white/55">
                                <span>Uploading {uploadProg.percent === 100 ? '- processing...' : `${uploadProg.percent}%`}</span>
                                <span className="tabular-nums">
                                    {fmtMB(uploadProg.loaded)} / {fmtMB(uploadProg.total)}
                                    {uploadProg.percent < 100 ? ` - ${fmtSpeed(uploadProg.bytesPerSec)}` : ''}
                                </span>
                            </div>
                        </div>
                    )}
                </section>

                {/* Live / most recent run */}
                {activeId !== null && <ActiveRun runId={activeId} />}

                {/* Recent runs */}
                {runs && runs.length > 0 && (
                    <section className={card}>
                        <h2 className="mb-3 text-sm font-bold text-white">Recent imports</h2>
                        <ul className="space-y-1.5">
                            {runs.map((r) => (
                                <li key={r.id}>
                                    <button
                                        onClick={() => setActiveId(r.id)}
                                        className="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-xs ring-1 ring-white/[0.06] transition hover:bg-white/[0.03]"
                                    >
                                        <span className="truncate text-white/75">{r.original_filename}</span>
                                        <span className={`shrink-0 font-semibold uppercase ${STATUS_TONE[r.status] ?? ''}`}>
                                            {r.status}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </div>
    );
}
