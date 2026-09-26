import { useMemo, useState } from 'react';
import { CaretRight, MagnifyingGlass } from '@phosphor-icons/react';
import { useQuery } from '@tanstack/react-query';
import { useDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import { linkColor } from '../../topology/lib/linkColor';
import { formatRate } from '../../../lib/formatRate';
import { devicePageKeys, fetchHistory, useHistoryCatalog } from '../api/devicePage';
import { openDevicePage } from '../lib/location';
import { fmtValue } from '../lib/format';
import { Empty } from './ui';
import type { Device } from '../../../types';

function speedLabel(mbps: number | null): string {
    if (!mbps) return '-';
    return mbps >= 1000 ? `${(mbps / 1000).toFixed(mbps % 1000 === 0 ? 0 : 1)}G` : `${mbps}M`;
}

/**
 * Port error counts over the last hour, when the backend records an error/discard family keyed by
 * interface. Until then this is just an empty map and the column stays hidden.
 */
function usePortErrors(deviceId: number) {
    const { data: catalog } = useHistoryCatalog(deviceId);
    const fam = catalog?.families.find((f) => f.owner === 'interfaces' && f.metrics.some((m) => m.group === 'port_errors' && /error|discard/.test(m.metric)));
    const metrics = fam?.metrics.filter((m) => /error|discard/.test(m.metric)).map((m) => m.metric) ?? [];
    const to = Math.floor(Date.now() / 300_000) * 300;
    const q = { family: fam?.family ?? '', metrics, from: to - 3600, to, points: 12 };
    return useQuery({
        queryKey: devicePageKeys.history(deviceId, q),
        queryFn: () => fetchHistory(deviceId, q),
        enabled: !!fam && metrics.length > 0,
        select: (r) => {
            const out = new Map<string, { value: number; unit: string | null }>();
            for (const s of r.series) {
                if (s.key === null || s.stats.last === null) continue;
                const prev = out.get(s.key);
                out.set(s.key, { value: (prev?.value ?? 0) + s.stats.last, unit: s.unit });
            }
            return out;
        },
    });
}

/** Every interface with its state and current load. Click through to the port page. */
export function PortsTab({ device, params }: { device: Device; params: URLSearchParams }) {
    const { data: ifaces, isLoading } = useDeviceInterfaces(device.id);
    const { data: errors } = usePortErrors(device.id);
    const [query, setQuery] = useState('');

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        return (ifaces ?? []).filter((i) => !q || i.name.toLowerCase().includes(q) || (i.description ?? '').toLowerCase().includes(q));
    }, [ifaces, query]);
    const optical = (ifaces ?? []).some((i) => i.optical_rx_dbm !== null || i.optical_tx_dbm !== null);
    const showErrors = !!errors && errors.size > 0;

    // keep the graph range when stepping into a port
    const keep = () => {
        const q = new URLSearchParams();
        for (const k of ['range', 'from', 'to', 'cmp']) {
            const v = params.get(k);
            if (v) q.set(k, v);
        }
        return q;
    };

    if (device.poll_method === 'none') return <Empty>Ping-only device, no interfaces are polled.</Empty>;
    if (isLoading) return <p className="text-sm text-white/35">Loading...</p>;
    if ((ifaces ?? []).length === 0) return <Empty>{device.discovery_error ?? 'No interfaces discovered yet, they appear after a poll.'}</Empty>;

    const th = 'px-3 py-2 text-left text-[10px] font-medium uppercase tracking-[0.14em] text-white/30';
    const td = 'px-3 py-2';

    return (
        <div className="space-y-3">
            <div className="relative max-w-sm">
                <MagnifyingGlass weight="light" className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search ports..."
                    className="w-full rounded-lg bg-white/[0.04] py-1.5 pr-3 pl-8 text-xs text-white ring-1 ring-white/10 outline-none placeholder:text-white/30 focus:ring-emerald-400/40"
                />
            </div>
            <div className="overflow-x-auto rounded-2xl bg-white/[0.02] ring-1 ring-white/[0.06]">
                <table className="w-full min-w-[46rem] text-xs">
                    <thead className="border-b border-white/[0.06]">
                        <tr>
                            <th className={th}>Port</th>
                            <th className={th}>Status</th>
                            <th className={`${th} text-right`}>Speed</th>
                            <th className={`${th} text-right`}>In</th>
                            <th className={`${th} text-right`}>Out</th>
                            <th className={th}>Util</th>
                            {showErrors && <th className={`${th} text-right`} title="Errors and discards in the last hour">Errors</th>}
                            {optical && <th className={`${th} text-right`}>Optical Rx / Tx</th>}
                            <th className={th} />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((i) => {
                            const u = i.util_in !== null || i.util_out !== null ? Math.max(i.util_in ?? 0, i.util_out ?? 0) : null;
                            const err = errors?.get(String(i.id));
                            return (
                                <tr
                                    key={i.id}
                                    onClick={() => openDevicePage(device.id, i.id, keep())}
                                    className="cursor-pointer border-b border-white/[0.04] text-white/75 transition-colors last:border-0 hover:bg-white/[0.03]"
                                >
                                    <td className={`${td} max-w-[18rem]`}>
                                        <div className="truncate font-medium text-white/85">{i.name}</div>
                                        {i.description && <div className="truncate text-[10px] text-white/40">{i.description}</div>}
                                    </td>
                                    <td className={td}>
                                        {i.oper_status === 'down' ? (
                                            <span className="rounded bg-rose-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-300 ring-1 ring-rose-400/25">down</span>
                                        ) : i.oper_status === 'up' ? (
                                            <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-300 ring-1 ring-emerald-400/20">up</span>
                                        ) : (
                                            <span className="text-white/30">-</span>
                                        )}
                                    </td>
                                    <td className={`${td} text-right font-mono`}>{speedLabel(i.speed_mbps)}</td>
                                    <td className={`${td} text-right font-mono tabular-nums`}>{formatRate(i.bps_in)}</td>
                                    <td className={`${td} text-right font-mono tabular-nums`}>{formatRate(i.bps_out)}</td>
                                    <td className={td}>
                                        {u === null ? (
                                            <span className="text-white/30">-</span>
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                <div className="h-1 w-20 overflow-hidden rounded-full bg-white/10">
                                                    <div className="h-full rounded-full" style={{ width: `${Math.min(100, Math.max(0, u))}%`, background: linkColor(u, false) }} />
                                                </div>
                                                <span className="font-mono text-[11px] tabular-nums">{u.toFixed(u < 10 ? 1 : 0)}%</span>
                                            </div>
                                        )}
                                    </td>
                                    {showErrors && <td className={`${td} text-right font-mono tabular-nums ${err && err.value > 0 ? 'text-amber-300' : ''}`}>{err ? fmtValue(err.value, err.unit) : '-'}</td>}
                                    {optical && (
                                        <td className={`${td} text-right font-mono text-[11px] text-sky-200/70`}>
                                            {i.optical_rx_dbm === null && i.optical_tx_dbm === null ? '-' : `${i.optical_rx_dbm?.toFixed(2) ?? '-'} / ${i.optical_tx_dbm?.toFixed(2) ?? '-'}`}
                                        </td>
                                    )}
                                    <td className={`${td} w-6 text-white/25`}>
                                        <CaretRight weight="bold" className="h-3.5 w-3.5" />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <p className="text-[11px] text-white/30">
                {rows.length} of {ifaces?.length ?? 0} ports. Rates are the latest poll; open a port for its history and 95th percentile billing.
            </p>
        </div>
    );
}
