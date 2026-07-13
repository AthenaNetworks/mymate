import { useState } from 'react';
import { TrashSimple, ArrowRight, ListDashes, DownloadSimple, PencilSimple, Pause, Play } from '@phosphor-icons/react';
import { useDevices } from '../api/getDevices';
import { useDeleteDevice } from '../api/deleteDevice';
import { useUpdateDevice } from '../api/updateDevice';
import { useUpgradeDevices, useUpgradePreflight, type UpgradePlanRow } from '../api/upgradeDevices';
import { DeviceForm } from './DeviceForm';
import { DeviceEditModal } from './DeviceEditModal';
import { useIsAdmin } from '../../auth/api/auth';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceTypeBadge } from '../../../components/DeviceTypeBadge';
import { UpgradeStatusBadge } from '../../../components/UpgradeStatusBadge';
import { selectDevice, setView } from '../../../lib/shellStore';
import { ConfirmDialog } from '../../../components/Dialog';
import { pushToast } from '../../../lib/toast';
import type { Device } from '../../../types';

type PendingUpgrade = { ids: number[]; willUpgrade: UpgradePlanRow[]; skipped: UpgradePlanRow[] };

/** Full-page device management - add form on the left, the device list on the right. */
export function DevicesView() {
    const isAdmin = useIsAdmin();
    const { data: devices, isLoading } = useDevices();
    const del = useDeleteDevice();
    const update = useUpdateDevice();
    const [editing, setEditing] = useState<Device | null>(null);

    function toggleMonitored(d: Device) {
        update.mutate(
            { id: d.id, monitored: !d.monitored },
            { onError: () => pushToast({ title: "Couldn't change monitoring", tone: 'down' }) },
        );
    }
    const upgrade = useUpgradeDevices();
    const preflight = useUpgradePreflight();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [ordered, setOrdered] = useState(false);
    const [pendingUpgrade, setPendingUpgrade] = useState<PendingUpgrade | null>(null);
    const [deleting, setDeleting] = useState<Device | null>(null);

    function open(id: number) {
        selectDevice(id);
        setView('map');
    }

    function toggle(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    async function upgradeSelected() {
        const ids = [...selected];
        if (ids.length === 0) return;

        // Dependency pre-flight: show what will upgrade vs be skipped first.
        let plan;
        try {
            plan = await preflight.mutateAsync({ deviceIds: ids });
        } catch {
            pushToast({ title: 'Couldn\'t check upgrade dependencies', tone: 'down' });
            return;
        }
        const willUpgrade = plan.plan.filter((p) => p.action === 'upgrade');
        const skipped = plan.plan.filter((p) => p.action === 'skip');
        setPendingUpgrade({ ids, willUpgrade, skipped });
    }

    function runUpgrade() {
        if (!pendingUpgrade) return;
        const { ids } = pendingUpgrade;
        upgrade.mutate(
            { deviceIds: ids, ordered },
            {
                onSuccess: (r) => {
                    pushToast({
                        title: `Upgrade queued for ${r.queued} device${r.queued > 1 ? 's' : ''}`,
                        detail: r.ordered ? 'Downstream-first; rebooting in order' : 'Downloading + rebooting',
                        tone: 'info',
                    });
                    setSelected(new Set());
                    setPendingUpgrade(null);
                },
                onError: () => {
                    pushToast({ title: 'Couldn\'t queue upgrades', tone: 'down' });
                    setPendingUpgrade(null);
                },
            },
        );
    }

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise">
                <header className="mb-6 flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                            <ListDashes weight="light" className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-base font-bold tracking-tight text-white">Devices</h1>
                            <p className="truncate text-xs text-white/40">Add, inspect, and manage monitored devices</p>
                        </div>
                    </div>
                    <span className="shrink-0 text-xs tabular-nums text-white/35">{devices?.length ?? 0} total</span>
                </header>

                <div className={`grid min-w-0 items-start gap-6 ${isAdmin ? 'lg:grid-cols-[21rem_1fr]' : ''}`}>
                    {isAdmin && (
                        <div className="min-w-0 rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06] lg:sticky lg:top-0">
                            <p className="mb-3 text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Add device</p>
                            <DeviceForm />
                        </div>
                    )}

                    <div className="min-w-0 rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2 px-1">
                            <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">All devices</p>
                            {isAdmin && selected.size > 0 && (
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <label className="flex cursor-pointer items-center gap-1.5 text-[11px] text-white/55">
                                        <input
                                            type="checkbox"
                                            checked={ordered}
                                            onChange={(e) => setOrdered(e.target.checked)}
                                            className="h-3 w-3 cursor-pointer accent-amber-400"
                                        />
                                        Downstream-first
                                    </label>
                                    <button
                                        onClick={upgradeSelected}
                                        disabled={upgrade.isPending || preflight.isPending}
                                        className="group flex items-center gap-1.5 rounded-full bg-amber-500/15 px-3 py-1 text-xs font-medium text-amber-200 ring-1 ring-amber-400/25 transition-all duration-300 ease-fluid hover:bg-amber-500/25 active:scale-[0.98] disabled:opacity-50"
                                    >
                                        <DownloadSimple weight="bold" className="h-3.5 w-3.5" />
                                        {preflight.isPending ? 'Checking...' : upgrade.isPending ? 'Queuing...' : `Upgrade ${selected.size} selected`}
                                    </button>
                                </div>
                            )}
                        </div>

                        {isLoading && (
                            <ul className="space-y-1">
                                {[0, 1, 2].map((i) => (
                                    <li key={i} className="flex items-center gap-2.5 rounded-xl px-3 py-2.5">
                                        <span className="h-2 w-2 animate-pulse rounded-full bg-white/10" />
                                        <span className="h-3 w-40 animate-pulse rounded bg-white/10" />
                                    </li>
                                ))}
                            </ul>
                        )}

                        {!isLoading && (devices?.length ?? 0) === 0 && (
                            <p className="rounded-xl bg-white/[0.02] px-3 py-3 text-xs text-white/40 ring-1 ring-white/[0.06]">
                                No devices yet - add one on the left, or use <span className="text-white/60">Discover</span> to scan a subnet.
                            </p>
                        )}

                        <ul className="min-w-0 space-y-0.5">
                            {devices?.map((d) => {
                                const upgradable = d.poll_method === 'routeros'; // only RouterOS can be upgraded
                                return (
                                    <li
                                        key={d.id}
                                        className={`group flex items-center justify-between rounded-xl px-3 py-2.5 ring-1 transition-all duration-300 ease-fluid hover:bg-white/[0.04] hover:ring-white/10 ${
                                            selected.has(d.id) ? 'bg-amber-500/[0.06] ring-amber-400/20' : 'ring-transparent'
                                        }`}
                                    >
                                        {isAdmin && (
                                            <input
                                                type="checkbox"
                                                checked={selected.has(d.id)}
                                                onChange={() => toggle(d.id)}
                                                disabled={!upgradable}
                                                title={upgradable ? 'Select for bulk upgrade' : 'SNMP device - not upgradable (RouterOS only)'}
                                                className={`mr-2.5 h-3.5 w-3.5 shrink-0 accent-amber-400 ${upgradable ? 'cursor-pointer' : 'cursor-not-allowed opacity-25'}`}
                                            />
                                        )}
                                        <button onClick={() => open(d.id)} className="flex min-w-0 flex-1 items-center gap-2.5 text-left">
                                            <StatusDot status={d.status} />
                                            <DeviceTypeBadge type={d.device_type} className="h-6 w-7 shrink-0" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-white/85">{d.name}</span>
                                                <span className="block truncate text-xs text-white/35">{d.mgmt_ip}</span>
                                            </span>
                                        </button>
                                        <div className="flex shrink-0 items-center gap-2.5">
                                            {/* Live upgrade state / current version. */}
                                            <UpgradeStatusBadge device={d} />
                                            {/* Poll method = upgrade eligibility (RouterOS API can upgrade; SNMP can\'t). */}
                                            <span
                                                title={upgradable ? 'RouterOS API - upgradable' : 'SNMP - monitor only, not upgradable'}
                                                className={`hidden items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[10px] ring-1 sm:inline-flex ${
                                                    upgradable
                                                        ? 'bg-sky-500/10 text-sky-300 ring-sky-400/20'
                                                        : 'bg-white/5 text-white/40 ring-white/10'
                                                }`}
                                            >
                                                {upgradable ? (
                                                    <>
                                                        <DownloadSimple weight="bold" className="h-3 w-3" /> RouterOS
                                                    </>
                                                ) : (
                                                    'SNMP'
                                                )}
                                            </span>
                                            {/* Enable/disable monitoring inline - paused devices poll nothing. */}
                                            {isAdmin && (
                                                <button
                                                    onClick={() => toggleMonitored(d)}
                                                    title={d.monitored ? 'Monitoring on - click to pause' : 'Paused - click to resume'}
                                                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 transition-colors ${
                                                        d.monitored
                                                            ? 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/20 hover:bg-emerald-500/20'
                                                            : 'bg-amber-500/10 text-amber-300 ring-amber-400/25 hover:bg-amber-500/20'
                                                    }`}
                                                >
                                                    {d.monitored ? <Pause weight="bold" className="h-3 w-3" /> : <Play weight="bold" className="h-3 w-3" />}
                                                    <span className="hidden sm:inline">{d.monitored ? 'Live' : 'Paused'}</span>
                                                </button>
                                            )}
                                            <div className="flex items-center gap-1">
                                                {isAdmin && (
                                                    <button
                                                        onClick={() => setEditing(d)}
                                                        title="Edit device"
                                                        className="rounded-lg p-1 text-white/40 opacity-100 transition-all duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 lg:text-white/30 lg:opacity-0 lg:group-hover:opacity-100"
                                                    >
                                                        <PencilSimple weight="bold" className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() => open(d.id)}
                                                    title="Show on map"
                                                    className="rounded-lg p-1 text-white/40 opacity-100 transition-all duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 lg:text-white/30 lg:opacity-0 lg:group-hover:opacity-100"
                                                >
                                                    <ArrowRight weight="bold" className="h-4 w-4" />
                                                </button>
                                                {isAdmin && (
                                                    <button
                                                        onClick={() => setDeleting(d)}
                                                        title="Delete device"
                                                        className="rounded-lg p-1 text-white/40 opacity-100 transition-all duration-300 ease-fluid hover:bg-white/5 hover:text-rose-400 lg:text-white/30 lg:opacity-0 lg:group-hover:opacity-100"
                                                    >
                                                        <TrashSimple weight="light" className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            </div>

            {pendingUpgrade &&
                (pendingUpgrade.willUpgrade.length === 0 ? (
                    <ConfirmDialog
                        title="Nothing to upgrade"
                        icon={<DownloadSimple weight="light" className="h-5 w-5" />}
                        message={
                            <>
                                All {pendingUpgrade.ids.length} selected device(s) were skipped:
                                <ul className="mt-2 space-y-1">
                                    {pendingUpgrade.skipped.map((s) => (
                                        <li key={s.name} className="text-white/50">
                                            <span className="text-white/80">{s.name}</span> - {s.reason}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        }
                        confirmLabel="OK"
                        onConfirm={() => setPendingUpgrade(null)}
                        onClose={() => setPendingUpgrade(null)}
                    />
                ) : (
                    <ConfirmDialog
                        title="Bulk upgrade"
                        icon={<DownloadSimple weight="light" className="h-5 w-5" />}
                        message={
                            <>
                                Upgrade{' '}
                                <span className="font-semibold text-white/85">
                                    {pendingUpgrade.willUpgrade.length} device{pendingUpgrade.willUpgrade.length > 1 ? 's' : ''}
                                </span>
                                {ordered && ' downstream-first (each waits for the previous to recover)'}? Each downloads the latest RouterOS and
                                reboots.
                                {pendingUpgrade.skipped.length > 0 && (
                                    <>
                                        <div className="mt-3 text-white/45">Skipping {pendingUpgrade.skipped.length}:</div>
                                        <ul className="mt-1 space-y-1">
                                            {pendingUpgrade.skipped.map((s) => (
                                                <li key={s.name} className="text-white/50">
                                                    <span className="text-white/80">{s.name}</span> - {s.reason}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </>
                        }
                        confirmLabel={`Upgrade ${pendingUpgrade.willUpgrade.length}`}
                        busy={upgrade.isPending}
                        onConfirm={runUpgrade}
                        onClose={() => setPendingUpgrade(null)}
                    />
                ))}

            {editing && <DeviceEditModal device={editing} onClose={() => setEditing(null)} />}

            {deleting && (
                <ConfirmDialog
                    title="Delete device"
                    icon={<TrashSimple weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete <span className="font-semibold text-white/85">{deleting.name}</span>? It will stop being monitored and be removed
                            from every map. This can't be undone.
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={() =>
                        del.mutate(deleting.id, {
                            onSuccess: () => setDeleting(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t delete the device', tone: 'down' });
                                setDeleting(null);
                            },
                        })
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
