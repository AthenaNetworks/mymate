import { useState } from 'react';
import { ArrowCircleUp, ArrowUp, ArrowDown, ArrowRight, CaretLeft, Check, LinkSimple, Trash, Archive, DownloadSimple } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useUpgradePreflight, useUpgradeDevices, type UpgradePlanRow, type UpgradeSource } from '../../devices/api/upgradeDevices';
import { useRouterosCatalog, useFetchPackage, useDeletePackage } from '../api/routerosCatalog';
import { useIsAdmin } from '../../auth/api/auth';
import { UpgradeStatusBadge } from '../../../components/UpgradeStatusBadge';
import { StatusDot } from '../../../components/StatusDot';
import { ConfirmDialog } from '../../../components/Dialog';
import { selectDevice, setView } from '../../../lib/shellStore';
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
    const [version, setVersion] = useState(''); // '' = latest in the device's channel
    const [source, setSource] = useState<UpgradeSource>('mikrotik');

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
            const p = await preflight.mutateAsync({ deviceIds: ids, version: version || null });
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
            { deviceIds: ids, ordered: true, explicitOrder: true, version: version || null, source },
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

            <UpgradeQueue />

            {order === null ? (
                <>
                    <UpgradeOptions version={version} setVersion={setVersion} source={source} setSource={setSource} />
                    <SelectStep
                        candidates={candidates}
                        selected={selected}
                        onToggle={toggle}
                        onSelectAll={() => setSelected(new Set(candidates.map((d) => d.id)))}
                        onClear={() => setSelected(new Set())}
                        onPlan={plan}
                        planning={preflight.isPending}
                    />
                    <PackageCache />
                </>
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
                                <span className="hidden w-16 shrink-0 text-right font-mono text-[10px] text-white/45 sm:inline-block">
                                    {d.arch ?? '-'}
                                </span>
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
                                    {byId.get(row.device_id)?.arch && (
                                        <span className="shrink-0 rounded bg-white/[0.06] px-1.5 py-0.5 font-mono text-[10px] text-white/40">{byId.get(row.device_id)?.arch}</span>
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

const optField =
    'rounded-lg bg-white/[0.04] px-3 py-1.5 text-sm text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40';

/**
 * The live upgrade queue - every device currently checking / downloading / rebooting. Shown at
 * the top of the page so you can always come back to an in-flight run (the wizard below resets,
 * this reads live device state). Click a device to jump to it on the map.
 */
function UpgradeQueue() {
    const { data: devices } = useDevices();
    const active = (devices ?? []).filter((d) => d.upgrade_status && UPGRADE_IN_PROGRESS.has(d.upgrade_status));
    if (active.length === 0) return null;

    return (
        <div className="rounded-2xl bg-amber-500/[0.06] p-4 ring-1 ring-amber-400/20">
            <p className="mb-2 flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-[0.2em] text-amber-200/70">
                <ArrowCircleUp weight="bold" className="h-3.5 w-3.5" /> Upgrade queue ({active.length} running)
            </p>
            <ul className="space-y-1">
                {active.map((d) => (
                    <li key={d.id} className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm ring-1 ring-white/[0.06]">
                        <button onClick={() => { selectDevice(d.id); setView('map'); }} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                            <StatusDot status={d.status} />
                            <span className="min-w-0 flex-1 truncate font-medium text-white/85">{d.name}</span>
                            {d.upgrade_message && <span className="hidden truncate text-[11px] text-white/40 sm:inline">{d.upgrade_message}</span>}
                        </button>
                        <UpgradeStatusBadge device={d} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function UpgradeOptions({
    version,
    setVersion,
    source,
    setSource,
}: {
    version: string;
    setVersion: (v: string) => void;
    source: UpgradeSource;
    setSource: (s: UpgradeSource) => void;
}) {
    const { data: catalog } = useRouterosCatalog();

    return (
        <div className="rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
            <p className="mb-3 text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Target version &amp; source</p>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                <label className="flex items-center gap-2 text-sm text-white/60">
                    Version
                    <input
                        list="ros-versions"
                        value={version}
                        onChange={(e) => setVersion(e.target.value.trim())}
                        placeholder="Latest (leave blank)"
                        className={`${optField} w-48`}
                    />
                    <datalist id="ros-versions">
                        {(catalog?.channels ?? []).map((c) => (
                            <option key={`${c.major}-${c.channel}`} value={c.version}>{`v${c.major} ${c.channel}`}</option>
                        ))}
                    </datalist>
                </label>
                <label className="flex items-center gap-2 text-sm text-white/60">
                    Source
                    <select value={source} onChange={(e) => setSource(e.target.value as UpgradeSource)} className={optField} disabled={!version}>
                        <option value="mikrotik">MikroTik (router downloads direct)</option>
                        <option value="mirror">My Mate mirror (cached here)</option>
                    </select>
                </label>
            </div>
            <p className="mt-2 text-[11px] text-white/40">
                {version
                    ? source === 'mirror'
                        ? "Each device's arch package is cached here first, then the router pulls it from My Mate."
                        : 'Each router fetches the chosen version straight from MikroTik and reboots.'
                    : "Latest uses RouterOS's own channel update. Pick a version to install a specific release."}
            </p>
        </div>
    );
}

function PackageCache() {
    const { data: catalog } = useRouterosCatalog();
    const fetchPkg = useFetchPackage();
    const del = useDeletePackage();
    const [v, setV] = useState('');
    const [arch, setArch] = useState('');
    const packages = catalog?.packages ?? [];

    const statusTone: Record<string, string> = { ready: 'text-emerald-300', failed: 'text-rose-300', pending: 'text-amber-300' };

    return (
        <div className="rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
            <div className="mb-3 flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">
                    <Archive weight="light" className="h-3.5 w-3.5" /> Package cache
                </p>
                <span className="text-[11px] text-white/30">kept {catalog?.retention_days ?? 90} days</span>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input list="ros-cache-versions" value={v} onChange={(e) => setV(e.target.value.trim())} placeholder="version e.g. 7.15.3" className={`${optField} w-40`} />
                <datalist id="ros-cache-versions">
                    {(catalog?.channels ?? []).map((ch) => (
                        <option key={`${ch.major}-${ch.channel}`} value={ch.version}>{`v${ch.major} ${ch.channel}`}</option>
                    ))}
                </datalist>
                <select value={arch} onChange={(e) => setArch(e.target.value)} className={optField}>
                    <option value="">arch...</option>
                    {(catalog?.arches ?? []).map((a) => (
                        <option key={a} value={a}>
                            {a}
                        </option>
                    ))}
                </select>
                <button
                    onClick={() => { fetchPkg.mutate({ version: v, arch }); }}
                    disabled={!/^\d+\.\d+(\.\d+)?$/.test(v) || !arch || fetchPkg.isPending}
                    className="flex items-center gap-1.5 rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/25 hover:bg-emerald-500/25 disabled:opacity-40"
                >
                    <DownloadSimple weight="bold" className="h-3.5 w-3.5" /> Cache
                </button>
            </div>

            {packages.length === 0 ? (
                <p className="text-xs text-white/40">No packages cached. A "mirror" upgrade caches them automatically, or add one above.</p>
            ) : (
                <ul className="space-y-1">
                    {packages.map((p) => (
                        <li key={p.id} className="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs ring-1 ring-white/[0.06]">
                            <span className="min-w-0 flex-1 truncate">
                                <span className="font-mono text-white/80">{p.version} · {p.arch}</span>
                                <span className={`ml-2 ${statusTone[p.status] ?? 'text-white/40'}`}>{p.status}</span>
                                {p.size_bytes ? <span className="ml-2 text-white/35">{(p.size_bytes / 1048576).toFixed(1)} MB</span> : null}
                                {p.error ? <span className="ml-2 truncate text-rose-300/70">{p.error}</span> : null}
                            </span>
                            <button onClick={() => del.mutate(p.id)} title="Delete cached package" className="shrink-0 rounded-lg p-1 text-white/40 hover:bg-white/5 hover:text-rose-400">
                                <Trash weight="bold" className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Placeholder({ children }: { children: React.ReactNode }) {
    return (
        <div className="grid flex-1 place-items-center rounded-2xl bg-white/[0.02] p-10 text-center text-sm text-white/40 ring-1 ring-white/[0.06]">
            {children}
        </div>
    );
}
