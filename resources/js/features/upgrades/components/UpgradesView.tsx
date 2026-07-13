import { useState } from 'react';
import { ArrowCircleUp, ArrowUp, ArrowDown, ArrowRight, CaretLeft, Check, LinkSimple } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useUpgradePreflight, useUpgradeDevices, type UpgradePlanRow } from '../../devices/api/upgradeDevices';
import { useIsAdmin } from '../../auth/api/auth';
import { UpgradeStatusBadge } from '../../../components/UpgradeStatusBadge';
import { StatusDot } from '../../../components/StatusDot';
import { ConfirmDialog } from '../../../components/Dialog';
import { pushToast } from '../../../lib/toast';
import { UPGRADE_IN_PROGRESS, type Device } from '../../../types';

/**
 * Rolling upgrades: pick RouterOS devices, let the preflight order them
 * furthest-downstream-first (so a reboot never cuts the path to gear still pending),
 * eyeball the topology, re-order by hand if needed, then run them one at a time - the
 * engine waits for each device to come back online before starting the next.
 */
export function UpgradesView() {
    const isAdmin = useIsAdmin();
    const { data: devices } = useDevices();
    const preflight = useUpgradePreflight();
    const upgrade = useUpgradeDevices();

    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [order, setOrder] = useState<UpgradePlanRow[] | null>(null); // null = still selecting
    const [confirming, setConfirming] = useState(false);
    const [started, setStarted] = useState(false);

    const candidates = (devices ?? []).filter((d) => d.poll_method === 'routeros');
    const byId = new Map((devices ?? []).map((d) => [d.id, d]));
    const running = started && order !== null && order.some((r) => {
        const s = byId.get(r.device_id)?.upgrade_status;
        return s ? UPGRADE_IN_PROGRESS.has(s) : false;
    });

    if (!isAdmin) {
        return <Placeholder>Rolling upgrades are an admin-only action.</Placeholder>;
    }

    function toggle(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    async function plan() {
        const ids = [...selected];
        if (ids.length === 0) return;
        try {
            const p = await preflight.mutateAsync({ deviceIds: ids });
            // Order the rows by the preflight's furthest-first order.
            const rows = p.order.map((id) => p.plan.find((r) => r.device_id === id)).filter((r): r is UpgradePlanRow => !!r);
            setOrder(rows);
            setStarted(false);
        } catch {
            pushToast({ title: "Couldn't work out the upgrade order", tone: 'down' });
        }
    }

    function move(i: number, dir: -1 | 1) {
        setOrder((o) => {
            if (!o) return o;
            const j = i + dir;
            if (j < 0 || j >= o.length) return o;
            const a = [...o];
            [a[i], a[j]] = [a[j], a[i]];
            return a;
        });
    }

    function start() {
        if (!order) return;
        const ids = order.map((r) => r.device_id);
        upgrade.mutate(
            { deviceIds: ids, ordered: true, explicitOrder: true },
            {
                onSuccess: () => {
                    setStarted(true);
                    pushToast({ title: 'Rolling upgrade started', detail: 'Each device upgrades once the one before it is back online.', tone: 'info' });
                },
                onError: () => pushToast({ title: "Couldn't start the upgrade", tone: 'down' }),
            },
        );
        setConfirming(false);
    }

    const upgradable = order?.filter((r) => r.action === 'upgrade').length ?? 0;

    return (
        <div className="mx-auto flex h-full w-full max-w-4xl flex-col gap-5 overflow-y-auto p-6">
            <header className="flex items-center gap-3">
                <span className="grid h-10 w-10 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                    <ArrowCircleUp weight="light" className="h-6 w-6" />
                </span>
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-white">Rolling upgrades</h1>
                    <p className="text-sm text-white/45">Upgrade RouterOS gear in order, furthest out first, one at a time.</p>
                </div>
            </header>

            {order === null ? (
                <SelectStep
                    candidates={candidates}
                    selected={selected}
                    onToggle={toggle}
                    onSelectAll={() => setSelected(new Set(candidates.map((d) => d.id)))}
                    onClear={() => setSelected(new Set())}
                    onPlan={plan}
                    planning={preflight.isPending}
                />
            ) : (
                <ReviewStep
                    order={order}
                    byId={byId}
                    started={started}
                    running={running}
                    onMove={move}
                    onBack={() => { setOrder(null); setStarted(false); }}
                    onStart={() => setConfirming(true)}
                    upgradable={upgradable}
                    starting={upgrade.isPending}
                />
            )}

            {confirming && order && (
                <ConfirmDialog
                    title="Start rolling upgrade"
                    icon={<ArrowCircleUp weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Upgrade <span className="font-semibold text-white/85">{upgradable}</span> device{upgradable === 1 ? '' : 's'} in this order,
                            one at a time. Each device reboots into the new firmware and the next only starts once it is back online. This reboots live gear.
                        </>
                    }
                    confirmLabel={`Upgrade ${upgradable}`}
                    tone="danger"
                    busy={upgrade.isPending}
                    onConfirm={start}
                    onClose={() => setConfirming(false)}
                />
            )}
        </div>
    );
}

function SelectStep({
    candidates,
    selected,
    onToggle,
    onSelectAll,
    onClear,
    onPlan,
    planning,
}: {
    candidates: Device[];
    selected: Set<number>;
    onToggle: (id: number) => void;
    onSelectAll: () => void;
    onClear: () => void;
    onPlan: () => void;
    planning: boolean;
}) {
    return (
        <>
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium uppercase tracking-wide text-white/40">
                    Select devices - {selected.size} of {candidates.length}
                </p>
                <div className="flex items-center gap-2 text-xs">
                    <button onClick={onSelectAll} className="rounded-lg px-2 py-1 text-white/60 hover:bg-white/5 hover:text-white/90">Select all</button>
                    <button onClick={onClear} className="rounded-lg px-2 py-1 text-white/60 hover:bg-white/5 hover:text-white/90">Clear</button>
                </div>
            </div>

            {candidates.length === 0 ? (
                <Placeholder>No RouterOS devices to upgrade. Only RouterOS gear can be upgraded.</Placeholder>
            ) : (
                <div className="space-y-1.5">
                    {candidates.map((d) => {
                        const on = selected.has(d.id);
                        return (
                            <button
                                key={d.id}
                                onClick={() => onToggle(d.id)}
                                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left ring-1 transition-colors ${
                                    on ? 'bg-emerald-500/10 ring-emerald-400/30' : 'bg-white/[0.03] ring-white/[0.06] hover:bg-white/[0.05]'
                                }`}
                            >
                                <span className={`grid h-5 w-5 shrink-0 place-items-center rounded-md ring-1 ${on ? 'bg-emerald-500 ring-emerald-400 text-emerald-950' : 'ring-white/20'}`}>
                                    {on && <Check weight="bold" className="h-3.5 w-3.5" />}
                                </span>
                                <StatusDot status={d.status} />
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-white/85">{d.name}</span>
                                <span className="shrink-0 font-mono text-[11px] text-white/40">{d.mgmt_ip}</span>
                                <span className="w-24 shrink-0 text-right text-[11px] text-white/50">
                                    {d.os_version ? `v${d.os_version}` : 'unknown'}
                                    {d.latest_version && d.os_version !== d.latest_version ? ` -> v${d.latest_version}` : ''}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="flex justify-end">
                <button
                    onClick={onPlan}
                    disabled={selected.size === 0 || planning}
                    className="group flex items-center gap-2 rounded-full bg-emerald-500 py-2 pl-5 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    <span>{planning ? 'Planning...' : 'Plan upgrade order'}</span>
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform group-hover:translate-x-0.5">
                        <ArrowRight weight="bold" className="h-4 w-4" />
                    </span>
                </button>
            </div>
        </>
    );
}

function ReviewStep({
    order,
    byId,
    started,
    running,
    onMove,
    onBack,
    onStart,
    upgradable,
    starting,
}: {
    order: UpgradePlanRow[];
    byId: Map<number, Device>;
    started: boolean;
    running: boolean;
    onMove: (i: number, dir: -1 | 1) => void;
    onBack: () => void;
    onStart: () => void;
    upgradable: number;
    starting: boolean;
}) {
    return (
        <>
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium uppercase tracking-wide text-white/40">
                    Upgrade order - furthest out first{started ? '' : ' (drag to re-order with the arrows)'}
                </p>
                {!started && (
                    <button onClick={onBack} className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-white/60 hover:bg-white/5 hover:text-white/90">
                        <CaretLeft weight="bold" className="h-3.5 w-3.5" /> Change selection
                    </button>
                )}
            </div>

            <div className="space-y-1.5">
                {order.map((row, i) => {
                    const live = byId.get(row.device_id);
                    const skip = row.action === 'skip';
                    return (
                        <div
                            key={row.device_id}
                            className={`flex items-center gap-3 rounded-xl px-3 py-2.5 ring-1 ${
                                skip ? 'bg-white/[0.015] ring-white/[0.05] opacity-60' : 'bg-white/[0.03] ring-white/[0.06]'
                            }`}
                        >
                            <span className="w-5 shrink-0 text-center text-xs font-semibold tabular-nums text-white/35">{i + 1}</span>

                            {!started && !skip ? (
                                <span className="flex shrink-0 flex-col">
                                    <button onClick={() => onMove(i, -1)} disabled={i === 0} className="text-white/30 hover:text-white/80 disabled:opacity-20">
                                        <ArrowUp weight="bold" className="h-3 w-3" />
                                    </button>
                                    <button onClick={() => onMove(i, 1)} disabled={i === order.length - 1} className="text-white/30 hover:text-white/80 disabled:opacity-20">
                                        <ArrowDown weight="bold" className="h-3 w-3" />
                                    </button>
                                </span>
                            ) : (
                                <span className="w-3 shrink-0" />
                            )}

                            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-white/[0.06] text-[10px] font-semibold text-white/45 ring-1 ring-white/10" title={`${row.depth} hop(s) from the core`}>
                                {row.depth}
                            </span>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <StatusDot status={row.status} />
                                    <span className="truncate text-sm font-medium text-white/85">{row.name}</span>
                                    {row.os_version && (
                                        <span className="shrink-0 font-mono text-[10px] text-white/35">
                                            v{row.os_version}{row.latest_version && row.latest_version !== row.os_version ? ` -> v${row.latest_version}` : ''}
                                        </span>
                                    )}
                                </div>
                                {(row.parent_name || row.neighbours.length > 0) && (
                                    <div className="mt-0.5 flex items-center gap-1 truncate text-[11px] text-white/35">
                                        <LinkSimple weight="bold" className="h-3 w-3 shrink-0" />
                                        <span className="truncate">
                                            {[row.parent_name ? `up: ${row.parent_name}` : null, row.neighbours.length ? row.neighbours.join(', ') : null]
                                                .filter(Boolean)
                                                .join('  -  ')}
                                        </span>
                                    </div>
                                )}
                            </div>

                            <span className="shrink-0 text-right">
                                {skip ? (
                                    <span className="text-[11px] text-white/40" title={row.reason ?? undefined}>skip - {row.reason}</span>
                                ) : started && live ? (
                                    <UpgradeStatusBadge device={live} />
                                ) : (
                                    <span className="text-[11px] font-medium text-emerald-300/80">will upgrade</span>
                                )}
                            </span>
                        </div>
                    );
                })}
            </div>

            {!started ? (
                <div className="flex items-center justify-between">
                    <p className="text-xs text-white/40">{upgradable} of {order.length} will upgrade; the rest are skipped.</p>
                    <button
                        onClick={onStart}
                        disabled={upgradable === 0 || starting}
                        className="group flex items-center gap-2 rounded-full bg-emerald-500 py-2 pl-5 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        <span>Start rolling upgrade</span>
                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform group-hover:translate-x-0.5">
                            <ArrowRight weight="bold" className="h-4 w-4" />
                        </span>
                    </button>
                </div>
            ) : (
                <p className="text-center text-xs text-white/45">
                    {running ? 'Rolling upgrade in progress - each device starts once the one before it is back online.' : 'Rolling upgrade finished.'}
                </p>
            )}
        </>
    );
}

function Placeholder({ children }: { children: React.ReactNode }) {
    return (
        <div className="grid flex-1 place-items-center rounded-2xl bg-white/[0.02] p-10 text-center text-sm text-white/40 ring-1 ring-white/[0.06]">
            {children}
        </div>
    );
}
