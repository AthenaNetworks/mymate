import { useState } from 'react';
import { useDevices } from '../features/devices/api/getDevices';
import { useMaps } from '../features/maps/api/maps';
import type { AlertScope, DeviceType } from '../types';

const DEVICE_TYPES: DeviceType[] = ['router', 'switch', 'ap', 'server', 'internet', 'unknown'];

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

/**
 * Targeting editor shared by alert policies, maintenance windows and custom sensors: limit
 * something to all / a device type / a map / a specific device list. The `AlertScope` shape
 * matches the backend targeting bag (App\Support\DeviceScope).
 */
export function ScopeEditor({ scope, onChange }: { scope: AlertScope; onChange: (s: AlertScope) => void }) {
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
