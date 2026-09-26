import { useQueries } from '@tanstack/react-query';
import { useDevices } from '../../devices/api/getDevices';
import { deviceInterfaceKeys, fetchDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import type { AlertInterfaceFilter, AlertScope } from '../../../types';

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

/** Short label for the policy list row, or null when it's every port (nothing worth saying). */
export function interfaceFilterSummary(f: AlertInterfaceFilter | undefined): string | null {
    switch (f?.mode) {
        case 'linked':
            return 'linked ports';
        case 'match':
            return `ports: ${f.match ?? ''}`;
        case 'selected':
            return `${f.interface_ids?.length ?? 0} port(s)`;
        default:
            return null;
    }
}

/**
 * Pick which interfaces an interface-level policy watches (GitHub #22 / #11). Hand picking
 * only makes sense against a known device list, so that option needs the policy scoped to
 * specific devices, and lists their ports grouped by device.
 */
export function InterfaceFilterEditor({
    value,
    scope,
    allowAll = true,
    onChange,
}: {
    value: AlertInterfaceFilter;
    scope: AlertScope;
    allowAll?: boolean;
    onChange: (f: AlertInterfaceFilter) => void;
}) {
    const deviceIds = scope.type === 'devices' ? (scope.device_ids ?? []) : [];
    const { data: devices } = useDevices();
    const ifaceQueries = useQueries({
        queries: value.mode === 'selected' ? deviceIds.map((id) => ({ queryKey: deviceInterfaceKeys(id), queryFn: () => fetchDeviceInterfaces(id) })) : [],
    });
    const picked = value.interface_ids ?? [];
    const toggle = (id: number) =>
        onChange({ ...value, interface_ids: picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id] });
    const deviceName = (id: number) => devices?.find((d) => d.id === id)?.name ?? `device ${id}`;

    return (
        <div className="space-y-2 rounded-xl bg-white/[0.02] p-2.5 ring-1 ring-white/[0.06]">
            <label className="flex items-center justify-between gap-3 text-sm text-white/70">
                <span>Interfaces</span>
                <select
                    className="w-52 rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                    value={value.mode}
                    onChange={(e) => onChange({ ...value, mode: e.target.value as AlertInterfaceFilter['mode'] })}
                >
                    {allowAll && <option value="all">Every interface</option>}
                    <option value="linked">Only ones on a map link</option>
                    <option value="match">Name or description matches...</option>
                    <option value="selected">Pick interfaces...</option>
                </select>
            </label>

            {value.mode === 'linked' && (
                <p className="px-1 text-[11px] text-white/35">
                    Only ports that are one end of a link on a map - usually your uplinks and backhaul.
                </p>
            )}

            {value.mode === 'match' && (
                <>
                    <input className={field} placeholder="eg sfp*, ether1, vlan*" value={value.match ?? ''} onChange={(e) => onChange({ ...value, match: e.target.value })} />
                    <p className="px-1 text-[11px] text-white/35">
                        Comma separated, * and ? wildcards, not case sensitive. Checked against the port name and its
                        description/comment, so tagging ports "uplink" on the router and matching *uplink* works too.
                    </p>
                </>
            )}

            {value.mode === 'selected' &&
                (deviceIds.length === 0 ? (
                    <p className="px-1 text-[11px] text-amber-300/70">Set "Applies to" to specific devices above, then pick their ports here.</p>
                ) : (
                    <div className="max-h-52 space-y-2 overflow-y-auto rounded-lg bg-black/20 p-1.5 ring-1 ring-white/[0.06]">
                        {deviceIds.map((id, i) => {
                            const ifaces = ifaceQueries[i]?.data;
                            return (
                                <div key={id}>
                                    <p className="px-1.5 pb-0.5 text-[11px] font-medium text-white/45">{deviceName(id)}</p>
                                    {!ifaces && <p className="px-2 text-xs text-white/30">Loading...</p>}
                                    {ifaces?.length === 0 && <p className="px-2 text-xs text-white/30">No interfaces discovered yet.</p>}
                                    {ifaces?.map((f) => (
                                        <label key={f.id} className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm text-white/75 hover:bg-white/[0.04]">
                                            <input type="checkbox" className="h-4 w-4 shrink-0 accent-emerald-500" checked={picked.includes(f.id)} onChange={() => toggle(f.id)} />
                                            <span className="min-w-0 flex-1 truncate font-mono text-xs">{f.name}</span>
                                            {f.description && <span className="min-w-0 truncate text-[11px] text-white/35">{f.description}</span>}
                                        </label>
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                ))}
        </div>
    );
}
