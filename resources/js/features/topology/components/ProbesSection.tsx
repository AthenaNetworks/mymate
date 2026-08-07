import { useState } from 'react';
import { Plus, PencilSimple, Trash, Play, ShieldWarning } from '@phosphor-icons/react';
import { useProbes, useSaveProbe, useDeleteProbe, useTestProbe, type ProbeInput } from '../api/probes';
import { pushToast } from '../../../lib/toast';
import type { DeviceStatus, Probe, ProbeKind } from '../../../types';

const dotClass: Record<DeviceStatus, string> = {
    up: 'bg-emerald-400',
    down: 'bg-rose-400',
    unknown: 'bg-zinc-500',
};

const field =
    'w-full rounded-lg border-0 bg-white/5 px-2.5 py-1.5 text-xs text-white/90 ring-1 ring-white/10 placeholder:text-white/30 focus:ring-emerald-400/40';

/** Days until a TLS cert expires, or null. */
function certDays(iso: string | null): number | null {
    if (!iso) return null;
    return Math.round((new Date(iso).getTime() - Date.now()) / 86_400_000);
}

function blankProbe(kind: ProbeKind): ProbeInput {
    return {
        name: '',
        kind,
        enabled: true,
        interval_s: 60,
        timeout_ms: 5000,
        fail_threshold: 2,
        config: kind === 'http' ? { url: '', method: 'GET', expect_status: '200-399', verify_tls: true } : { port: 443 },
    };
}

/** Per-device service probes (GitHub #19): list + manage HTTP/TCP checks from the inspector. */
export function ProbesSection({ deviceId, isAdmin }: { deviceId: number; isAdmin: boolean }) {
    const { data: probes } = useProbes(deviceId);
    const save = useSaveProbe(deviceId);
    const del = useDeleteProbe(deviceId);
    const test = useTestProbe();
    const [editing, setEditing] = useState<ProbeInput | null>(null);

    function submit() {
        if (!editing) return;
        if (!editing.name.trim()) {
            pushToast({ title: 'Give the probe a name', tone: 'down' });
            return;
        }
        save.mutate(editing, {
            onSuccess: () => setEditing(null),
            onError: () => pushToast({ title: "Couldn't save the probe", tone: 'down' }),
        });
    }

    function runNow(p: Probe) {
        test.mutate(p.id, {
            onSuccess: (r) => pushToast({ title: `${p.name}: ${r.up ? 'up' : 'down'}`, detail: r.message ?? undefined, tone: r.up ? 'up' : 'down' }),
            onError: () => pushToast({ title: 'Test failed', tone: 'down' }),
        });
    }

    const setC = (patch: Partial<Probe['config']>) => editing && setEditing({ ...editing, config: { ...editing.config, ...patch } });

    return (
        <div className="space-y-1.5">
            {(probes ?? []).length === 0 && !editing && (
                <p className="text-xs text-white/35">No probes yet. Add an HTTP or TCP check for a service on this device.</p>
            )}

            {(probes ?? []).map((p) => {
                const days = certDays(p.cert_expires_at);
                return (
                    <div key={p.id} className="rounded-lg bg-white/[0.03] px-2.5 py-2 ring-1 ring-white/5">
                        <div className="flex items-center gap-2">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${dotClass[p.status] ?? dotClass.unknown} ${!p.enabled ? 'opacity-30' : ''}`} />
                            <span className="min-w-0 flex-1 truncate text-xs font-medium text-white/85">{p.name}</span>
                            <span className="shrink-0 rounded bg-white/5 px-1.5 text-[10px] uppercase text-white/40">{p.kind}</span>
                            {p.latency_ms != null && p.status === 'up' && (
                                <span className="shrink-0 font-mono text-[10px] tabular-nums text-white/45">{Math.round(p.latency_ms)}ms</span>
                            )}
                            {isAdmin && (
                                <div className="flex shrink-0 items-center gap-0.5">
                                    <button onClick={() => runNow(p)} title="Run now" className="rounded p-1 text-white/35 hover:text-emerald-300"><Play weight="bold" className="h-3 w-3" /></button>
                                    <button onClick={() => setEditing({ id: p.id, name: p.name, kind: p.kind, enabled: p.enabled, interval_s: p.interval_s, timeout_ms: p.timeout_ms, fail_threshold: p.fail_threshold, config: p.config })} title="Edit" className="rounded p-1 text-white/35 hover:text-white/70"><PencilSimple weight="bold" className="h-3 w-3" /></button>
                                    <button onClick={() => del.mutate(p.id)} title="Delete" className="rounded p-1 text-white/35 hover:text-rose-300"><Trash weight="bold" className="h-3 w-3" /></button>
                                </div>
                            )}
                        </div>
                        {(p.message || days != null) && (
                            <div className="mt-1 flex items-center gap-2 pl-4 text-[10px]">
                                {p.message && <span className="truncate text-white/40">{p.message}</span>}
                                {days != null && (
                                    <span className={`ml-auto flex shrink-0 items-center gap-1 ${days <= 21 ? 'text-amber-300' : 'text-white/35'}`}>
                                        {days <= 21 && <ShieldWarning weight="bold" className="h-3 w-3" />}
                                        cert {days}d
                                    </span>
                                )}
                            </div>
                        )}
                    </div>
                );
            })}

            {isAdmin && !editing && (
                <button onClick={() => setEditing(blankProbe('http'))} className="flex w-full items-center justify-center gap-1.5 rounded-lg bg-white/5 px-2 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 hover:bg-white/10 hover:text-white">
                    <Plus weight="bold" className="h-3.5 w-3.5 text-emerald-300" /> Add probe
                </button>
            )}

            {editing && (
                <div className="space-y-2 rounded-lg bg-white/[0.04] p-2.5 ring-1 ring-white/10">
                    <div className="flex gap-2">
                        <input className={field} placeholder="Probe name" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
                        <select className={`${field} w-24`} value={editing.kind} onChange={(e) => setEditing(blankProbe(e.target.value as ProbeKind))}>
                            <option value="http">HTTP</option>
                            <option value="tcp">TCP</option>
                        </select>
                    </div>

                    {editing.kind === 'http' ? (
                        <>
                            <input className={field} placeholder="https://host/health" value={editing.config.url ?? ''} onChange={(e) => setC({ url: e.target.value })} />
                            <div className="flex gap-2">
                                <select className={`${field} w-24`} value={editing.config.method ?? 'GET'} onChange={(e) => setC({ method: e.target.value as 'GET' | 'HEAD' | 'POST' })}>
                                    <option>GET</option><option>HEAD</option><option>POST</option>
                                </select>
                                <input className={field} placeholder="expected status (200-399)" value={editing.config.expect_status ?? ''} onChange={(e) => setC({ expect_status: e.target.value })} />
                            </div>
                            <input className={field} placeholder="body must contain (optional)" value={editing.config.expect_body ?? ''} onChange={(e) => setC({ expect_body: e.target.value })} />
                            <label className="flex items-center gap-2 text-xs text-white/60">
                                <input type="checkbox" checked={editing.config.verify_tls ?? true} onChange={(e) => setC({ verify_tls: e.target.checked })} /> Verify TLS certificate
                            </label>
                        </>
                    ) : (
                        <div className="flex gap-2">
                            <input className={field} placeholder="host (blank = device IP)" value={editing.config.host ?? ''} onChange={(e) => setC({ host: e.target.value })} />
                            <input className={`${field} w-24`} type="number" placeholder="port" value={editing.config.port ?? ''} onChange={(e) => setC({ port: Number(e.target.value) || undefined })} />
                        </div>
                    )}

                    <div className="flex items-center gap-2 text-[11px] text-white/45">
                        <label className="flex items-center gap-1">every <input className={`${field} w-14`} type="number" value={editing.interval_s ?? 60} onChange={(e) => setEditing({ ...editing, interval_s: Number(e.target.value) || 60 })} />s</label>
                        <label className="flex items-center gap-1">fails <input className={`${field} w-12`} type="number" value={editing.fail_threshold ?? 2} onChange={(e) => setEditing({ ...editing, fail_threshold: Number(e.target.value) || 1 })} /></label>
                    </div>

                    <div className="flex justify-end gap-2 pt-0.5">
                        <button onClick={() => setEditing(null)} className="rounded-lg px-2.5 py-1 text-xs text-white/55 hover:text-white/85">Cancel</button>
                        <button onClick={submit} disabled={save.isPending} className="rounded-lg bg-emerald-500/20 px-2.5 py-1 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/25 hover:bg-emerald-500/30 disabled:opacity-50">Save</button>
                    </div>
                </div>
            )}
        </div>
    );
}
