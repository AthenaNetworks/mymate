import { useState } from 'react';
import { Archive, ArrowClockwise, CircleNotch, Clock, GitCommit } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import {
    useBackupSchedule,
    useUpdateBackupSchedule,
    useRunAllBackups,
    useDeviceVersions,
    useDeviceDiff,
    useDeviceConfigAt,
} from '../api/backups';
import { useIsAdmin } from '../../auth/api/auth';
import { StatusDot } from '../../../components/StatusDot';
import { relativeTime } from '../../../lib/relativeTime';
import { pushToast } from '../../../lib/toast';
import type { BackupFrequency, BackupScheduleConfig, Device } from '../../../types';

const FREQS: { value: BackupFrequency; label: string }[] = [
    { value: 'hourly', label: 'Every hour' },
    { value: 'every_6h', label: 'Every 6 hours' },
    { value: 'every_12h', label: 'Every 12 hours' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const field = 'rounded-lg bg-white/[0.04] px-2 py-1 text-xs text-white/85 outline-none ring-1 ring-white/10 focus:ring-emerald-400/50';

/** Colourised unified-diff renderer. */
function DiffView({ text }: { text: string | null | undefined }) {
    if (text === null || text === undefined) return <p className="p-4 text-xs text-white/35">Loading...</p>;
    if (text.trim() === '') return <p className="p-4 text-xs text-white/40">No changes in this version.</p>;
    return (
        <pre className="overflow-auto p-3 font-mono text-[11px] leading-relaxed">
            {text.split('\n').map((line, i) => {
                let cls = 'text-white/55';
                if (line.startsWith('+') && !line.startsWith('+++')) cls = 'bg-emerald-500/[0.07] text-emerald-300';
                else if (line.startsWith('-') && !line.startsWith('---')) cls = 'bg-rose-500/[0.07] text-rose-300';
                else if (line.startsWith('@@')) cls = 'text-sky-300';
                else if (/^(diff |index |\+\+\+|---|new file|deleted)/.test(line)) cls = 'text-white/25';
                return (
                    <div key={i} className={cls}>
                        {line || ' '}
                    </div>
                );
            })}
        </pre>
    );
}

function ScheduleCard({ isAdmin }: { isAdmin: boolean }) {
    const { data: schedule } = useBackupSchedule();
    const update = useUpdateBackupSchedule();
    const runAll = useRunAllBackups();

    function save(patch: Partial<BackupScheduleConfig>) {
        if (!schedule) return;
        const { enabled, frequency, hour, weekday } = { ...schedule, ...patch };
        update.mutate({ enabled, frequency, hour, weekday }, { onError: () => pushToast({ title: "Couldn't save schedule", tone: 'down' }) });
    }

    return (
        <div className="rounded-2xl bg-white/[0.03] p-4 ring-1 ring-white/[0.06]">
            <div className="mb-3 flex items-center justify-between">
                <span className="flex items-center gap-2 text-sm font-semibold text-white/85">
                    <Clock weight="light" className="h-4 w-4 text-white/50" /> Schedule
                </span>
                {isAdmin && (
                    <button
                        type="button"
                        role="switch"
                        aria-checked={schedule?.enabled ?? false}
                        onClick={() => save({ enabled: !schedule?.enabled })}
                        className={`relative h-5 w-9 shrink-0 rounded-full transition-colors ${schedule?.enabled ? 'bg-emerald-500' : 'bg-white/15'}`}
                    >
                        <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white transition-transform ${schedule?.enabled ? 'translate-x-4' : 'translate-x-0.5'}`} />
                    </button>
                )}
            </div>

            {schedule ? (
                <div className="space-y-2.5 text-xs text-white/60">
                    <div className="flex items-center justify-between gap-2">
                        <span>Frequency</span>
                        <select className={field} value={schedule.frequency} disabled={!isAdmin} onChange={(e) => save({ frequency: e.target.value as BackupFrequency })}>
                            {FREQS.map((f) => (
                                <option key={f.value} value={f.value}>{f.label}</option>
                            ))}
                        </select>
                    </div>
                    {(schedule.frequency === 'daily' || schedule.frequency === 'weekly') && (
                        <div className="flex items-center justify-between gap-2">
                            <span>At hour</span>
                            <select className={field} value={schedule.hour} disabled={!isAdmin} onChange={(e) => save({ hour: Number(e.target.value) })}>
                                {Array.from({ length: 24 }, (_, h) => (
                                    <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>
                                ))}
                            </select>
                        </div>
                    )}
                    {schedule.frequency === 'weekly' && (
                        <div className="flex items-center justify-between gap-2">
                            <span>On</span>
                            <select className={field} value={schedule.weekday} disabled={!isAdmin} onChange={(e) => save({ weekday: Number(e.target.value) })}>
                                {WEEKDAYS.map((d, i) => (
                                    <option key={i} value={i}>{d}</option>
                                ))}
                            </select>
                        </div>
                    )}
                    {schedule.last_run_at && (
                        <p className="text-[11px] text-white/35">Last scheduled run {relativeTime(schedule.last_run_at)}.</p>
                    )}
                    {isAdmin && (
                        <button
                            onClick={() => runAll.mutate(undefined, { onSuccess: (r) => pushToast({ title: `Queued ${r.queued} backup(s)`, tone: 'info' }), onError: () => pushToast({ title: "Couldn't start backups", tone: 'down' }) })}
                            disabled={runAll.isPending}
                            className="mt-1 flex w-full items-center justify-center gap-1.5 rounded-lg bg-white/[0.05] px-2 py-1.5 text-xs font-medium text-white/80 ring-1 ring-white/10 transition hover:bg-white/[0.09] disabled:opacity-40"
                        >
                            {runAll.isPending ? <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" /> : <ArrowClockwise weight="bold" className="h-3.5 w-3.5" />}
                            Back up all now
                        </button>
                    )}
                </div>
            ) : (
                <p className="text-xs text-white/35">Loading...</p>
            )}
        </div>
    );
}

function VersionPanel({ device }: { device: Device }) {
    const { data: versions, isLoading } = useDeviceVersions(device.id);
    const [focus, setFocus] = useState<string | null>(null);
    const [mode, setMode] = useState<'diff' | 'config'>('diff');
    const commit = focus ?? versions?.[0]?.commit ?? null;

    const diff = useDeviceDiff(device.id, mode === 'diff' ? commit : null, null);
    const config = useDeviceConfigAt(device.id, mode === 'config' ? commit : null);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="mb-3 flex items-center justify-between">
                <div>
                    <h2 className="text-base font-bold tracking-tight text-white">{device.name}</h2>
                    <p className="text-xs text-white/40">{device.mgmt_ip} - backup history</p>
                </div>
                <div className="flex items-center gap-1 rounded-lg bg-white/[0.04] p-0.5 ring-1 ring-white/10">
                    {(['diff', 'config'] as const).map((m) => (
                        <button
                            key={m}
                            onClick={() => setMode(m)}
                            className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${mode === m ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80'}`}
                        >
                            {m === 'diff' ? 'Changes' : 'Full config'}
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex min-h-0 flex-1 gap-3">
                {/* Version list. */}
                <div className="w-56 shrink-0 space-y-1 overflow-auto">
                    {isLoading ? (
                        <p className="text-xs text-white/35">Loading...</p>
                    ) : !versions || versions.length === 0 ? (
                        <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">No stored versions yet - run a backup.</p>
                    ) : (
                        versions.map((v) => (
                            <button
                                key={v.commit}
                                onClick={() => setFocus(v.commit)}
                                className={`w-full rounded-lg px-2.5 py-2 text-left ring-1 transition ${commit === v.commit ? 'bg-emerald-500/10 ring-emerald-400/25' : 'bg-white/[0.03] ring-white/[0.06] hover:bg-white/[0.05]'}`}
                            >
                                <div className="flex items-center gap-1.5 text-[11px] text-white/45">
                                    <GitCommit weight="bold" className="h-3 w-3" />
                                    <span className="font-mono">{v.commit}</span>
                                    <span className="ml-auto">{v.date}</span>
                                </div>
                                <div className="mt-0.5 truncate text-[11px] text-white/60">{v.subject}</div>
                            </button>
                        ))
                    )}
                </div>

                {/* Diff / config viewer. */}
                <div className="min-w-0 flex-1 overflow-hidden rounded-xl bg-[#0b0b0f] ring-1 ring-white/10">
                    {commit === null ? (
                        <p className="p-4 text-xs text-white/35">No version selected.</p>
                    ) : mode === 'diff' ? (
                        <DiffView text={diff.data} />
                    ) : config.data ? (
                        <pre className="overflow-auto p-3 font-mono text-[11px] leading-relaxed text-white/75">{config.data}</pre>
                    ) : (
                        <p className="p-4 text-xs text-white/35">{config.isLoading ? 'Loading...' : 'No config at this version.'}</p>
                    )}
                </div>
            </div>
        </div>
    );
}

/** Full-page config-backup overview: schedule, backup-enabled devices, and git-backed
 *  version history with diffs. */
export function BackupsView() {
    const isAdmin = useIsAdmin();
    const { data: devices } = useDevices();
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const backupDevices = (devices ?? []).filter((d) => d.backup_enabled);
    const selected = backupDevices.find((d) => d.id === selectedId) ?? null;

    return (
        <div className="flex h-full min-h-0 w-full gap-4 p-6">
            {/* Left: schedule + device list. */}
            <div className="flex w-72 shrink-0 flex-col gap-4 overflow-y-auto">
                <header className="flex items-center gap-2.5">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <Archive weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-base font-bold tracking-tight text-white">Backups</h1>
                        <p className="text-[11px] text-white/40">{backupDevices.length} device(s)</p>
                    </div>
                </header>

                <ScheduleCard isAdmin={isAdmin} />

                <div className="space-y-1">
                    {backupDevices.length === 0 ? (
                        <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">
                            No devices have backups enabled. Enable them from a device's inspector.
                        </p>
                    ) : (
                        backupDevices.map((d) => (
                            <button
                                key={d.id}
                                onClick={() => setSelectedId(d.id)}
                                className={`flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left ring-1 transition ${selectedId === d.id ? 'bg-emerald-500/10 ring-emerald-400/25' : 'bg-white/[0.03] ring-white/[0.06] hover:bg-white/[0.05]'}`}
                            >
                                <StatusDot status={d.status} />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-white/85">{d.name}</span>
                                    <span className="block truncate text-[11px] text-white/40">
                                        {d.backup_at ? `backed up ${relativeTime(d.backup_at)}` : 'never backed up'}
                                    </span>
                                </span>
                                {d.backup_status === 'failed' && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-rose-400" title="Last backup failed" />}
                            </button>
                        ))
                    )}
                </div>
            </div>

            {/* Right: version history + diff. */}
            <div className="flex min-h-0 flex-1 flex-col rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
                {selected ? (
                    <VersionPanel key={selected.id} device={selected} />
                ) : (
                    <div className="grid flex-1 place-items-center text-sm text-white/35">Pick a device to see its backup history and diffs.</div>
                )}
            </div>
        </div>
    );
}
