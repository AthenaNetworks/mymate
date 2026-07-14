import { useState } from 'react';
import { Bell, Plus, PencilSimple, Trash, PaperPlaneTilt } from '@phosphor-icons/react';
import { ConfirmDialog } from '../../../components/Dialog';
import { useAlertPolicies, useSaveAlertPolicy, useDeleteAlertPolicy, type AlertPolicyInput } from '../api/alertPolicies';
import { useAlertTransports, useSaveAlertTransport, useDeleteAlertTransport, useTestTransport, type AlertTransportInput } from '../api/alertTransports';
import { useAlertEvents } from '../api/alertEvents';
import { useIsAdmin } from '../../auth/api/auth';
import { useDevices } from '../../devices/api/getDevices';
import { useMaps } from '../../maps/api/maps';
import { relativeTime } from '../../../lib/relativeTime';
import { pushToast } from '../../../lib/toast';
import type { AlertConditionType, AlertPolicy, AlertScope, AlertTransport, DeviceType } from '../../../types';

const DEVICE_TYPES: DeviceType[] = ['router', 'switch', 'ap', 'server', 'internet', 'unknown'];
// device-scoped conditions; new_discovery is fleet-wide (candidates aren\'t devices yet).
const SCOPED_CONDITIONS: AlertConditionType[] = ['device_down', 'high_util', 'upgrade_failed', 'backup_failed', 'high_metric'];

/** Short targeting label for the policy list row. */
function scopeSummary(scope: AlertScope): string {
    switch (scope.type) {
        case 'device_type':
            return `type: ${scope.device_type ?? '-'}`;
        case 'map':
            return 'on a map';
        case 'devices':
            return `${scope.device_ids?.length ?? 0} device(s)`;
        default:
            return 'all devices';
    }
}

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';
const card = 'rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const iconBtn = 'rounded-md p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80';

const CONDITIONS: { value: AlertConditionType; label: string }[] = [
    { value: 'device_down', label: 'Device down / recovered' },
    { value: 'high_util', label: 'Sustained high utilisation' },
    { value: 'high_metric', label: 'High device metric (CPU / memory / temperature)' },
    { value: 'upgrade_failed', label: 'Upgrade failed' },
    { value: 'backup_failed', label: 'Config backup failed' },
    { value: 'new_discovery', label: 'New device discovered' },
];

/** Targeting editor: limit a policy to all / a device type / a map / a device list. */
function ScopeEditor({ scope, onChange }: { scope: AlertScope; onChange: (s: AlertScope) => void }) {
    const { data: devices } = useDevices();
    const { data: maps } = useMaps();
    const [q, setQ] = useState('');
    const ids = scope.device_ids ?? [];
    const query = q.trim().toLowerCase();
    const list = (devices ?? []).filter((d) => !query || d.name.toLowerCase().includes(query) || d.mgmt_ip.includes(query));
    const toggle = (id: number) =>
        onChange({ ...scope, device_ids: ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id] });

    return (
        <div className="space-y-2 rounded-xl bg-white/[0.02] p-2.5 ring-1 ring-white/[0.06]">
            <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                <span>Applies to</span>
                <select
                    className="w-44 rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                    value={scope.type}
                    onChange={(e) => onChange({ type: e.target.value as AlertScope['type'] })}
                >
                    <option value="all">All devices</option>
                    <option value="device_type">Device type...</option>
                    <option value="map">A map...</option>
                    <option value="devices">Specific devices...</option>
                </select>
            </label>

            {scope.type === 'device_type' && (
                <select
                    className={field}
                    value={scope.device_type ?? ''}
                    onChange={(e) => onChange({ ...scope, device_type: e.target.value as DeviceType })}
                >
                    <option value="" disabled>
                        Choose a type...
                    </option>
                    {DEVICE_TYPES.map((t) => (
                        <option key={t} value={t}>
                            {t}
                        </option>
                    ))}
                </select>
            )}

            {scope.type === 'map' && (
                <select className={field} value={scope.map_id ?? ''} onChange={(e) => onChange({ ...scope, map_id: Number(e.target.value) })}>
                    <option value="" disabled>
                        Choose a map...
                    </option>
                    {(maps ?? []).map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name}
                        </option>
                    ))}
                </select>
            )}

            {scope.type === 'devices' && (
                <div className="space-y-1.5">
                    <input className={field} placeholder="Search devices..." value={q} onChange={(e) => setQ(e.target.value)} />
                    <p className="px-1 text-[11px] text-white/35">{ids.length} selected</p>
                    <div className="max-h-40 space-y-0.5 overflow-y-auto rounded-lg bg-black/20 p-1 ring-1 ring-white/[0.06]">
                        {list.map((d) => (
                            <label key={d.id} className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm text-white/75 hover:bg-white/[0.04]">
                                <input type="checkbox" className="h-4 w-4 shrink-0 accent-emerald-500" checked={ids.includes(d.id)} onChange={() => toggle(d.id)} />
                                <span className="min-w-0 flex-1 truncate">{d.name}</span>
                                <span className="shrink-0 font-mono text-[11px] text-white/35">{d.mgmt_ip}</span>
                            </label>
                        ))}
                        {list.length === 0 && <p className="px-2 py-2 text-center text-xs text-white/35">No devices match.</p>}
                    </div>
                </div>
            )}
        </div>
    );
}

function PolicyForm({ initial, transports, onDone }: { initial?: AlertPolicy; transports: AlertTransport[]; onDone: () => void }) {
    const save = useSaveAlertPolicy();
    const [form, setForm] = useState<AlertPolicyInput>({
        id: initial?.id,
        name: initial?.name ?? '',
        condition: initial?.condition ?? 'device_down',
        params: {
            threshold: initial?.params?.threshold ?? 90,
            duration_minutes: initial?.params?.duration_minutes ?? 0,
            suppress_dependent: initial?.params?.suppress_dependent ?? true,
            metric: initial?.params?.metric ?? 'cpu',
        },
        scope: initial?.scope ?? { type: 'all' },
        enabled: initial?.enabled ?? true,
        transport_ids: initial?.transport_ids ?? [],
    });
    const set = <K extends keyof AlertPolicyInput>(k: K, v: AlertPolicyInput[K]) => setForm((f) => ({ ...f, [k]: v }));
    const toggleTransport = (id: number) =>
        set('transport_ids', (form.transport_ids ?? []).includes(id) ? (form.transport_ids ?? []).filter((t) => t !== id) : [...(form.transport_ids ?? []), id]);

    function submit() {
        save.mutate(form, {
            onSuccess: () => {
                pushToast({ title: initial ? 'Policy updated' : 'Policy added', tone: 'info' });
                onDone();
            },
            onError: () => pushToast({ title: 'Couldn\'t save policy', tone: 'down' }),
        });
    }

    return (
        <div className="space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <input className={field} placeholder="Policy name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <select className={field} value={form.condition} onChange={(e) => set('condition', e.target.value as AlertConditionType)}>
                {CONDITIONS.map((c) => (
                    <option key={c.value} value={c.value}>
                        {c.label}
                    </option>
                ))}
            </select>
            {SCOPED_CONDITIONS.includes(form.condition) && (
                <ScopeEditor scope={form.scope ?? { type: 'all' }} onChange={(s) => set('scope', s)} />
            )}
            {form.condition === 'device_down' && (
                <>
                    <label className="flex items-start justify-between gap-3 text-sm text-white/70">
                        <span>
                            Suppress dependents
                            <span className="mt-0.5 block text-[11px] text-white/35">
                                Don't alert on devices behind a down parent - only the root-cause device fires.
                            </span>
                        </span>
                        <input
                            type="checkbox"
                            className="mt-1 h-4 w-4 shrink-0 accent-emerald-500"
                            checked={form.params?.suppress_dependent ?? true}
                            onChange={(e) => set('params', { ...form.params, suppress_dependent: e.target.checked })}
                        />
                    </label>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>
                            Down for (min) before notifying
                            <span className="ml-1 text-[11px] text-white/35">0 = notify immediately</span>
                        </span>
                        <input
                            type="number"
                            min={0}
                            max={1440}
                            value={form.params?.duration_minutes ?? 0}
                            onChange={(e) => set('params', { ...form.params, duration_minutes: Number(e.target.value) })}
                            className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                        />
                    </label>
                    <p className="px-1 text-[11px] text-white/35">
                        A device that recovers before this window elapses never notifies (no down + recovery spam for
                        brief flaps). The Dashboard and map always show live status regardless of this setting.
                    </p>
                </>
            )}
            {form.condition === 'high_util' && (
                <>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>Utilisation threshold (%)</span>
                        <input
                            type="number"
                            min={1}
                            max={100}
                            value={form.params?.threshold ?? 90}
                            onChange={(e) => set('params', { ...form.params, threshold: Number(e.target.value) })}
                            className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                        />
                    </label>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>
                            Sustained for (min)
                            <span className="ml-1 text-[11px] text-white/35">0 = instant</span>
                        </span>
                        <input
                            type="number"
                            min={0}
                            max={1440}
                            value={form.params?.duration_minutes ?? 0}
                            onChange={(e) => set('params', { ...form.params, duration_minutes: Number(e.target.value) })}
                            className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                        />
                    </label>
                    <p className="px-1 text-[11px] text-white/35">
                        Evaluated per link, against its effective speed (override or slowest end).
                    </p>
                </>
            )}
            {form.condition === 'high_metric' && (
                <>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>Metric</span>
                        <select
                            className="w-44 rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                            value={form.params?.metric ?? 'cpu'}
                            onChange={(e) => set('params', { ...form.params, metric: e.target.value as 'cpu' | 'mem' | 'temp' })}
                        >
                            <option value="cpu">CPU load</option>
                            <option value="mem">Memory used</option>
                            <option value="temp">Temperature</option>
                        </select>
                    </label>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>Threshold ({form.params?.metric === 'temp' ? '°C' : '%'})</span>
                        <input
                            type="number"
                            min={1}
                            max={form.params?.metric === 'temp' ? 200 : 100}
                            value={form.params?.threshold ?? 90}
                            onChange={(e) => set('params', { ...form.params, threshold: Number(e.target.value) })}
                            className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                        />
                    </label>
                    <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                        <span>
                            Sustained for (min)
                            <span className="ml-1 text-[11px] text-white/35">0 = instant</span>
                        </span>
                        <input
                            type="number"
                            min={0}
                            max={1440}
                            value={form.params?.duration_minutes ?? 0}
                            onChange={(e) => set('params', { ...form.params, duration_minutes: Number(e.target.value) })}
                            className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                        />
                    </label>
                    <p className="px-1 text-[11px] text-white/35">
                        Uses each device's latest CPU / memory / temperature reading. Stale readings (a device that
                        stopped reporting) are ignored - a down device alerts via "Device down" instead.
                    </p>
                </>
            )}
            {form.condition === 'backup_failed' && (
                <p className="px-1 text-[11px] text-white/35">
                    Fires when a device's most recent config backup failed, and resolves when its next backup
                    succeeds. One alert per device - a device that keeps failing won't re-notify each run.
                </p>
            )}
            <div>
                <p className="mb-1 px-1 text-[11px] font-medium text-white/45">Deliver via</p>
                <div className="flex flex-wrap gap-1.5">
                    {transports.length === 0 && <span className="px-1 text-xs text-white/35">No transports yet - add one below.</span>}
                    {transports.map((t) => {
                        const on = (form.transport_ids ?? []).includes(t.id);
                        return (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => toggleTransport(t.id)}
                                className={`rounded-full px-3 py-1 text-xs ring-1 transition ${on ? 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/30' : 'text-white/55 ring-white/10 hover:text-white/85'}`}
                            >
                                {t.name}
                            </button>
                        );
                    })}
                </div>
            </div>
            <label className="flex items-center gap-2 px-1 text-sm text-white/70">
                <input type="checkbox" checked={form.enabled ?? true} onChange={(e) => set('enabled', e.target.checked)} /> Enabled
            </label>
            <div className="flex items-center justify-end gap-2 pt-1">
                <button onClick={onDone} className="rounded-full px-3 py-1.5 text-sm text-white/55 hover:text-white/90">
                    Cancel
                </button>
                <button
                    onClick={submit}
                    disabled={save.isPending || form.name === ''}
                    className="rounded-full bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {save.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

function TransportForm({ initial, onDone }: { initial?: AlertTransport; onDone: () => void }) {
    const save = useSaveAlertTransport();
    const [form, setForm] = useState<AlertTransportInput>({
        id: initial?.id,
        name: initial?.name ?? '',
        type: initial?.type ?? 'slack',
        enabled: initial?.enabled ?? true,
    });
    const set = <K extends keyof AlertTransportInput>(k: K, v: AlertTransportInput[K]) => setForm((f) => ({ ...f, [k]: v }));
    const keepHint = initial ? ' (leave blank to keep)' : '';

    function submit() {
        save.mutate(form, {
            onSuccess: () => {
                pushToast({ title: initial ? 'Transport updated' : 'Transport added', tone: 'info' });
                onDone();
            },
            onError: () => pushToast({ title: 'Couldn\'t save transport', tone: 'down' }),
        });
    }

    return (
        <div className="space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <input className={field} placeholder="Transport name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <select className={field} value={form.type} onChange={(e) => set('type', e.target.value as AlertTransportInput['type'])}>
                <option value="slack">Slack</option>
                <option value="teams">Microsoft Teams</option>
                <option value="messenger">Messenger</option>
                <option value="email">Email</option>
            </select>
            {form.type === 'email' ? (
                <input className={field} placeholder={`Email address${keepHint}`} value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} />
            ) : (
                <input className={field} placeholder={`Incoming webhook URL${keepHint}`} value={form.webhook_url ?? ''} onChange={(e) => set('webhook_url', e.target.value)} />
            )}
            <label className="flex items-center gap-2 px-1 text-sm text-white/70">
                <input type="checkbox" checked={form.enabled ?? true} onChange={(e) => set('enabled', e.target.checked)} /> Enabled
            </label>
            <div className="flex items-center justify-end gap-2 pt-1">
                <button onClick={onDone} className="rounded-full px-3 py-1.5 text-sm text-white/55 hover:text-white/90">
                    Cancel
                </button>
                <button
                    onClick={submit}
                    disabled={save.isPending || form.name === ''}
                    className="rounded-full bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {save.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

export function AlertsView() {
    const isAdmin = useIsAdmin();
    const { data: policies } = useAlertPolicies();
    const { data: transports } = useAlertTransports();
    const { data: events } = useAlertEvents();
    const delPolicy = useDeleteAlertPolicy();
    const delTransport = useDeleteAlertTransport();
    const testTransport = useTestTransport();

    const [addingPolicy, setAddingPolicy] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<number | null>(null);
    const [addingTransport, setAddingTransport] = useState(false);
    const [editingTransport, setEditingTransport] = useState<number | null>(null);
    const [deletingPolicy, setDeletingPolicy] = useState<AlertPolicy | null>(null);
    const [deletingTransport, setDeletingTransport] = useState<AlertTransport | null>(null);

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise mx-auto max-w-2xl space-y-6">
                <header className="flex items-center gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <Bell weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-base font-bold tracking-tight text-white">Alerts</h1>
                        <p className="text-xs text-white/40">Policies, transports &amp; recent alert events</p>
                    </div>
                </header>

                {/* Recent events */}
                <section className={card}>
                    <h2 className="mb-3 text-sm font-bold text-white">Recent</h2>
                    {!events || events.length === 0 ? (
                        <p className="text-xs text-white/40">No alerts yet.</p>
                    ) : (
                        <div className="space-y-1.5">
                            {events.slice(0, 12).map((e) => (
                                <div key={e.id} className="flex items-center gap-2 text-xs">
                                    <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${e.status === 'firing' ? 'bg-rose-400' : 'bg-emerald-400'}`} />
                                    <span className="min-w-0 flex-1 truncate text-white/75">{e.message}</span>
                                    <span className="shrink-0 text-white/35">{relativeTime(e.fired_at)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {/* Policies */}
                <section className={card}>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-bold text-white">Policies</h2>
                        {isAdmin && !addingPolicy && (
                            <button onClick={() => { setAddingPolicy(true); setEditingPolicy(null); }} className="flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 hover:text-white">
                                <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                            </button>
                        )}
                    </div>
                    {addingPolicy && <PolicyForm transports={transports ?? []} onDone={() => setAddingPolicy(false)} />}
                    <div className="mt-2 space-y-2">
                        {policies?.map((p) =>
                            editingPolicy === p.id ? (
                                <PolicyForm key={p.id} initial={p} transports={transports ?? []} onDone={() => setEditingPolicy(null)} />
                            ) : (
                                <div key={p.id} className="flex items-center gap-2 rounded-xl bg-white/[0.02] px-3 py-2 text-sm ring-1 ring-white/[0.06]">
                                    <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${p.enabled ? 'bg-emerald-400' : 'bg-white/20'}`} />
                                    <span className="min-w-0 flex-1 truncate text-white/85">
                                        {p.name}
                                        <span className="text-white/35"> - {p.condition_label}</span>
                                        {p.scope && p.scope.type !== 'all' && <span className="text-emerald-300/60"> - {scopeSummary(p.scope)}</span>}
                                    </span>
                                    {isAdmin && (
                                        <>
                                            <button onClick={() => { setEditingPolicy(p.id); setAddingPolicy(false); }} className={iconBtn}>
                                                <PencilSimple weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                            <button onClick={() => setDeletingPolicy(p)} className={`${iconBtn} hover:bg-rose-500/10 hover:text-rose-300`}>
                                                <Trash weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                        </>
                                    )}
                                </div>
                            ),
                        )}
                        {policies && policies.length === 0 && !addingPolicy && <p className="text-xs text-white/40">No policies yet.</p>}
                    </div>
                </section>

                {/* Transports */}
                <section className={card}>
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-bold text-white">Transports</h2>
                            <p className="text-xs text-white/40">Email, Slack &amp; Teams - webhooks/addresses are encrypted, never shown.</p>
                        </div>
                        {isAdmin && !addingTransport && (
                            <button onClick={() => { setAddingTransport(true); setEditingTransport(null); }} className="flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 hover:text-white">
                                <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                            </button>
                        )}
                    </div>
                    {addingTransport && <TransportForm onDone={() => setAddingTransport(false)} />}
                    <div className="mt-2 space-y-2">
                        {transports?.map((t) =>
                            editingTransport === t.id ? (
                                <TransportForm key={t.id} initial={t} onDone={() => setEditingTransport(null)} />
                            ) : (
                                <div key={t.id} className="flex items-center gap-2 rounded-xl bg-white/[0.02] px-3 py-2 text-sm ring-1 ring-white/[0.06]">
                                    <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${t.enabled ? 'bg-emerald-400' : 'bg-white/20'}`} />
                                    <span className="min-w-0 flex-1 truncate text-white/85">
                                        {t.name}
                                        <span className="text-white/35"> - {t.type}</span>
                                    </span>
                                    {isAdmin && (
                                        <>
                                            <button
                                                onClick={() =>
                                                    testTransport.mutate(t.id, {
                                                        onSuccess: () => pushToast({ title: `Test sent to ${t.name}`, tone: 'info' }),
                                                        onError: () => pushToast({ title: `Test failed for ${t.name}`, tone: 'down' }),
                                                    })
                                                }
                                                title="Send test"
                                                className={iconBtn}
                                            >
                                                <PaperPlaneTilt weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                            <button onClick={() => { setEditingTransport(t.id); setAddingTransport(false); }} className={iconBtn}>
                                                <PencilSimple weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                            <button onClick={() => setDeletingTransport(t)} className={`${iconBtn} hover:bg-rose-500/10 hover:text-rose-300`}>
                                                <Trash weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                        </>
                                    )}
                                </div>
                            ),
                        )}
                        {transports && transports.length === 0 && !addingTransport && <p className="text-xs text-white/40">No transports yet.</p>}
                    </div>
                </section>
            </div>

            {deletingPolicy && (
                <ConfirmDialog
                    title="Delete policy"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete the policy <span className="font-semibold text-white/85">{deletingPolicy.name}</span>? This can't be undone.
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={delPolicy.isPending}
                    onConfirm={() =>
                        delPolicy.mutate(deletingPolicy.id, {
                            onSuccess: () => setDeletingPolicy(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t delete the policy', tone: 'down' });
                                setDeletingPolicy(null);
                            },
                        })
                    }
                    onClose={() => setDeletingPolicy(null)}
                />
            )}

            {deletingTransport && (
                <ConfirmDialog
                    title="Delete transport"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete the transport <span className="font-semibold text-white/85">{deletingTransport.name}</span>? Alert policies using
                            it will stop delivering there.
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={delTransport.isPending}
                    onConfirm={() =>
                        delTransport.mutate(deletingTransport.id, {
                            onSuccess: () => setDeletingTransport(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t delete the transport', tone: 'down' });
                                setDeletingTransport(null);
                            },
                        })
                    }
                    onClose={() => setDeletingTransport(null)}
                />
            )}
        </div>
    );
}
