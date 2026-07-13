import { useState } from 'react';
import { ArrowClockwise, CircleNotch, Eye, FloppyDisk, X } from '@phosphor-icons/react';
import { useDeviceBackups, useDeviceLatestConfig, useProvisionSshKey, useRunBackup, useUpdateBackupConfig } from '../api/backups';
import { pushToast } from '../../../lib/toast';
import { relativeTime } from '../../../lib/relativeTime';
import { RUSTED_DRIVERS, type BackupStatus, type Device } from '../../../types';

const statusStyle: Record<BackupStatus, { label: string; cls: string }> = {
    ok: { label: 'Backed up', cls: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    unchanged: { label: 'Unchanged', cls: 'bg-sky-500/15 text-sky-300 ring-sky-400/25' },
    pending: { label: 'Backing up...', cls: 'bg-amber-500/15 text-amber-300 ring-amber-400/25' },
    failed: { label: 'Failed', cls: 'bg-rose-500/15 text-rose-300 ring-rose-400/25' },
};

const actionBtn =
    'flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white disabled:opacity-40';

function fmtBytes(bytes: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} kB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** A read-only modal showing the latest stored config text (fetched on open). */
function ConfigViewer({ device, onClose }: { device: Device; onClose: () => void }) {
    const { data: config, isLoading } = useDeviceLatestConfig(device.id, true);
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" onClick={onClose}>
            <div
                className="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-[#0d0d11] ring-1 ring-white/10"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-bold text-white">{device.name} - latest config</p>
                        <p className="text-[11px] text-white/40">Read-only - captured by the backup engine over SSH</p>
                    </div>
                    <button onClick={onClose} title="Close" className="rounded-lg p-1.5 text-white/40 hover:bg-white/5 hover:text-white/80">
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                </div>
                <div className="min-h-0 flex-1 overflow-auto p-4">
                    {isLoading ? (
                        <div className="flex items-center gap-2 text-sm text-white/40">
                            <CircleNotch weight="bold" className="h-4 w-4 animate-spin" /> Loading config...
                        </div>
                    ) : config ? (
                        <pre className="overflow-x-auto whitespace-pre font-mono text-[11px] leading-relaxed text-white/80">{config}</pre>
                    ) : (
                        <p className="text-sm text-white/40">No stored config yet - run a backup first.</p>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * Per-device config-backup panel for the inspector. Shows the last run\'s
 * status, a "back up now" button, recent history, and a config viewer. Writes (enable/driver,
 * run) are admin-only; a read-only operator still sees status/history + can view the config.
 */
export function BackupSection({ device, isAdmin }: { device: Device; isAdmin: boolean }) {
    const { data: history } = useDeviceBackups(device.id);
    const updateConfig = useUpdateBackupConfig();
    const runBackup = useRunBackup();
    const provision = useProvisionSshKey();
    const [viewing, setViewing] = useState(false);

    // MikroTik can't export config over the API, so offer to bootstrap key-based SSH over
    // the API (installs a generated key on the device) when the device has no SSH cred yet.
    const canProvision = device.poll_method === 'routeros' && device.ssh_credential_id === null;

    function provisionKey() {
        provision.mutate(device.id, {
            onSuccess: (r) => pushToast({ title: 'Key-based SSH ready', detail: r.message, tone: 'up' }),
            onError: (e: unknown) => {
                const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
                pushToast({ title: 'Couldn\'t set up SSH key', detail: msg, tone: 'down' });
            },
        });
    }

    const status = device.backup_status;
    const running = status === 'pending' || runBackup.isPending;
    const badge = status ? statusStyle[status] : null;
    const suggested = RUSTED_DRIVERS.find((d) => d.value === device.backup_driver);

    function toggle(enabled: boolean) {
        updateConfig.mutate(
            { id: device.id, backup_enabled: enabled },
            {
                onSuccess: () => pushToast({ title: enabled ? 'Backups enabled' : 'Backups disabled', tone: 'info' }),
                onError: (e: unknown) => {
                    const msg = (e as { response?: { data?: { errors?: { backup_driver?: string[] } } } })?.response?.data?.errors?.backup_driver?.[0];
                    pushToast({ title: 'Couldn\'t change backups', detail: msg, tone: 'down' });
                },
            },
        );
    }

    function setDriver(driver: string) {
        updateConfig.mutate(
            { id: device.id, backup_enabled: device.backup_enabled, backup_driver: driver },
            { onError: () => pushToast({ title: 'Couldn\'t set driver', tone: 'down' }) },
        );
    }

    function runNow() {
        runBackup.mutate(device.id, {
            onSuccess: () => pushToast({ title: `${device.name}: backup queued`, tone: 'info' }),
            onError: (e: unknown) => {
                const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
                pushToast({ title: 'Couldn\'t start backup', detail: msg, tone: 'down' });
            },
        });
    }

    return (
        <div className="space-y-2.5">
            <div className="flex items-baseline justify-between">
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Config backups</p>
                {badge && (
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ${badge.cls}`}>
                        {running && <CircleNotch weight="bold" className="h-3 w-3 animate-spin" />}
                        {badge.label}
                    </span>
                )}
            </div>

            {!device.backup_enabled ? (
                <div className="space-y-2">
                    <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">
                        Backups are off for this device. {isAdmin ? 'Enable them to capture its config over SSH.' : 'An admin can enable them.'}
                    </p>
                    {isAdmin && (
                        <button onClick={() => toggle(true)} disabled={updateConfig.isPending} className={`${actionBtn} w-full justify-center`}>
                            <FloppyDisk weight="light" className="h-3.5 w-3.5" /> {updateConfig.isPending ? 'Enabling...' : 'Enable backups'}
                        </button>
                    )}
                </div>
            ) : (
                <div className="space-y-2.5">
                    {/* Driver + last-run detail. */}
                    <div className="flex items-center justify-between gap-2 text-xs">
                        <span className="text-white/45">Driver</span>
                        {isAdmin ? (
                            <select
                                value={device.backup_driver ?? ''}
                                onChange={(e) => setDriver(e.target.value)}
                                className="max-w-[60%] truncate rounded-md bg-white/[0.03] px-1.5 py-0.5 text-right text-white/85 outline-none ring-1 ring-white/10 transition hover:ring-white/25 focus:ring-emerald-400/50"
                            >
                                {RUSTED_DRIVERS.map((d) => (
                                    <option key={d.value} value={d.value}>{d.label}</option>
                                ))}
                            </select>
                        ) : (
                            <span className="truncate text-white/70">{suggested?.label ?? device.backup_driver ?? '-'}</span>
                        )}
                    </div>

                    {/* MikroTik key-based-SSH bootstrap (RouterOS can't export over the API). */}
                    {isAdmin && canProvision && (
                        <button
                            onClick={provisionKey}
                            disabled={provision.isPending}
                            title="Install an SSH key on this MikroTik over its API, then back it up over SSH"
                            className="flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-500/10 px-2 py-1.5 text-xs font-medium text-emerald-300 ring-1 ring-emerald-400/25 transition hover:bg-emerald-500/20 disabled:opacity-40"
                        >
                            {provision.isPending ? <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" /> : null}
                            {provision.isPending ? 'Setting up SSH key...' : 'Set up key-based SSH (over API)'}
                        </button>
                    )}
                    {isAdmin && device.poll_method === 'routeros' && device.ssh_credential_id !== null && (
                        <p className="text-[11px] text-emerald-300/70">Key-based SSH is set up for this device.</p>
                    )}

                    {device.backup_at && (
                        <div className="flex items-center justify-between text-xs">
                            <span className="text-white/45">Last run</span>
                            <span className="text-white/70">{relativeTime(device.backup_at)}</span>
                        </div>
                    )}
                    {status === 'failed' && device.backup_message && (
                        <p className="rounded-lg bg-rose-500/5 px-2.5 py-1.5 text-[11px] text-rose-300/80 ring-1 ring-rose-400/15">{device.backup_message}</p>
                    )}

                    {/* Actions. */}
                    <div className={`grid gap-2 ${isAdmin ? 'grid-cols-2' : 'grid-cols-1'}`}>
                        {isAdmin && (
                            <button onClick={runNow} disabled={running} className={actionBtn}>
                                {running ? <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" /> : <ArrowClockwise weight="bold" className="h-3.5 w-3.5" />}
                                {running ? 'Backing up...' : 'Back up now'}
                            </button>
                        )}
                        <button onClick={() => setViewing(true)} disabled={!device.backup_commit} className={actionBtn} title={device.backup_commit ? 'View the latest stored config' : 'No config stored yet'}>
                            <Eye weight="light" className="h-3.5 w-3.5" /> View config
                        </button>
                    </div>

                    {/* Recent history. */}
                    {history && history.length > 0 && (
                        <div className="space-y-1 pt-0.5">
                            {history.slice(0, 5).map((h, i) => (
                                <div key={h.commit ?? i} className="flex items-center gap-2 text-[11px]">
                                    <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${h.status === 'failed' ? 'bg-rose-400' : h.status === 'unchanged' ? 'bg-sky-400' : 'bg-emerald-400'}`} />
                                    <span className="min-w-0 flex-1 truncate text-white/55">{h.finished_at ? relativeTime(h.finished_at) : h.status}</span>
                                    {h.bytes ? <span className="shrink-0 tabular-nums text-white/30">{fmtBytes(h.bytes)}</span> : null}
                                    {h.commit ? <span className="shrink-0 font-mono text-white/30">{h.commit.slice(0, 7)}</span> : null}
                                </div>
                            ))}
                        </div>
                    )}

                    {isAdmin && (
                        <button onClick={() => toggle(false)} disabled={updateConfig.isPending} className="w-full text-left text-[11px] text-white/30 transition hover:text-white/60">
                            Disable backups for this device
                        </button>
                    )}
                </div>
            )}

            {viewing && <ConfigViewer device={device} onClose={() => setViewing(false)} />}
        </div>
    );
}
