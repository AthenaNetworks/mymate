import { useState } from 'react';
import { TrashSimple, ArrowRight, ListDashes, DownloadSimple, PencilSimple, Pause, Play, MagnifyingGlass } from '@phosphor-icons/react';
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

const DEVICE_TYPES: Device['device_type'][] = ['router', 'switch', 'ap', 'server', 'internet', 'unknown'];
const selectCls = 'rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40';

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
    const [confirmBulkDelete, setConfirmBulkDelete] = useState(false);

    // Search + filters.
    const [query, setQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'up' | 'down' | 'unknown'>('all');
    const [typeFilter, setTypeFilter] = useState<'all' | Device['device_type']>('all');
    const [monitoredFilter, setMonitoredFilter] = useState<'all' | 'live' | 'paused'>('all');
    const q = query.trim().toLowerCase();
    const filtered = (devices ?? []).filter(
        (d) =>
            (statusFilter === 'all' || d.status === statusFilter) &&
            (typeFilter === 'all' || d.device_type === typeFilter) &&
            (monitoredFilter === 'all' || (monitoredFilter === 'live') === d.monitored) &&
            (!q ||
                d.name.toLowerCase().includes(q) ||
                d.mgmt_ip.includes(q) ||
                (d.model ?? '').toLowerCase().includes(q) ||
                (d.vendor ?? '').toLowerCase().includes(q)),
    );
    const filtersActive = q !== '' || statusFilter !== 'all' || typeFilter !== 'all' || monitoredFilter !== 'all';
    const allSelected = filtered.length > 0 && filtered.every((d) => selected.has(d.id));

    function open(id: number) {
        selectDevice(id);
        setView('map');
    }

    function toggleAll() {
        setSelected((prev) => {
            const next = new Set(prev);
            if (filtered.every((d) => next.has(d.id))) {
                filtered.forEach((d) => next.delete(d.id));
            } else {
                filtered.forEach((d) => next.add(d.id));
            }
            return next;
        });
    }

    async function deleteSelected() {
        const ids = [...selected];
        await Promise.all(ids.map((id) => del.mutateAsync(id).catch(() => null)));
        setSelected(new Set());
        setConfirmBulkDelete(false);
        pushToast({ title: `Deleted ${ids.length} device${ids.length > 1 ? 's' : ''}`, tone: 'info' });
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
                        <div className="mb-3 space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2 px-1">
                                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">
                                    All devices <span className="text-white/25">({filtered.length})</span>
                                </p>
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
                                            {preflight.isPending ? 'Checking...' : upgrade.isPending ? 'Queuing...' : `Upgrade ${selected.size}`}
                                        </button>
                                        <button
                                            onClick={() => setConfirmBulkDelete(true)}
                                            disabled={del.isPending}
                                            className="flex items-center gap-1.5 rounded-full bg-rose-500/15 px-3 py-1 text-xs font-medium text-rose-200 ring-1 ring-rose-400/25 transition-all duration-300 ease-fluid hover:bg-rose-500/25 active:scale-[0.98] disabled:opacity-50"
                                        >
                                            <TrashSimple weight="bold" className="h-3.5 w-3.5" />
                                            Delete {selected.size}
                                        </button>
                                    </div>
                                )}
                            </div>

                            {/* Search + filters. */}
                            <div className="flex flex-wrap items-center gap-2 px-1">
                                <div className="relative min-w-[10rem] flex-1">
                                    <MagnifyingGlass weight="bold" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                                    <input
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        placeholder="Search name, IP, model..."
                                        className="w-full rounded-lg bg-white/[0.04] py-1.5 pl-8 pr-3 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40 placeholder:text-white/30"
                                    />
                                </div>
                                <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as typeof statusFilter)} className={selectCls}>
                                    <option value="all">Any status</option>
                                    <option value="up">Up</option>
                                    <option value="down">Down</option>
                                    <option value="unknown">Unknown</option>
                                </select>
                                <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as typeof typeFilter)} className={selectCls}>
                                    <option value="all">Any type</option>
                                    {DEVICE_TYPES.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                                <select value={monitoredFilter} onChange={(e) => setMonitoredFilter(e.target.value as typeof monitoredFilter)} className={selectCls}>
                                    <option value="all">Any state</option>
                                    <option value="live">Live</option>
                                    <option value="paused">Paused</option>
                                </select>
                                {filtersActive && (
                                    <button onClick={() => { setQuery(''); setStatusFilter('all'); setTypeFilter('all'); setMonitoredFilter('all'); }} className="rounded-lg px-2 py-1 text-xs text-white/45 ring-1 ring-white/10 hover:text-white/80">
                                        Clear
                                    </button>
                                )}
                                {isAdmin && filtered.length > 0 && (
                                    <label className="ml-auto flex cursor-pointer items-center gap-1.5 text-[11px] text-white/45">
                                        <input type="checkbox" checked={allSelected} onChange={toggleAll} className="h-3.5 w-3.5 cursor-pointer accent-amber-400" />
                                        Select all
                                    </label>
                                )}
                            </div>
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

                        {!isLoading && (devices?.length ?? 0) > 0 && filtered.length === 0 && (
                            <p className="rounded-xl bg-white/[0.02] px-3 py-3 text-xs text-white/40 ring-1 ring-white/[0.06]">
                                No devices match your search or filters.
                            </p>
                        )}

                        <ul className="min-w-0 space-y-0.5">
                            {filtered.map((d) => {
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
                                                title="Select (bulk upgrade / delete)"
                                                className="mr-2.5 h-3.5 w-3.5 shrink-0 cursor-pointer accent-amber-400"
                                            />
                                        )}
                                        <button onClick={() => open(d.id)} className="flex min-w-0 flex-1 items-center gap-2.5 text-left">
                                            <StatusDot status={d.status} />
                                            <DeviceTypeBadge type={d.device_type} className="h-6 w-7 shrink-0" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-white/85">{d.name}</span>
                                                <span className="flex items-center gap-2 truncate text-xs text-white/35">
                                                    <span className="shrink-0">{d.mgmt_ip}</span>
                                                    {(d.cpu_pct != null || d.mem_used_pct != null || d.temp_c != null) && (
                                                        <span className="hidden items-center gap-2 truncate font-mono text-[10px] text-white/30 sm:inline-flex">
                                                            {d.cpu_pct != null && <span>cpu {Math.round(d.cpu_pct)}%</span>}
                                                            {d.mem_used_pct != null && <span>mem {Math.round(d.mem_used_pct)}%</span>}
                                                            {d.temp_c != null && <span>{Math.round(d.temp_c)}&deg;</span>}
                                                        </span>
                                                    )}
                                                </span>
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

            {confirmBulkDelete && (
                <ConfirmDialog
                    title="Delete devices"
                    icon={<TrashSimple weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete <span className="font-semibold text-white/85">{selected.size} device{selected.size > 1 ? 's' : ''}</span>? They stop
                            being monitored and are removed from every map. This can't be undone.
                        </>
                    }
                    confirmLabel={`Delete ${selected.size}`}
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={deleteSelected}
                    onClose={() => setConfirmBulkDelete(false)}
                />
            )}

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
