import { useMemo } from 'react';
import { Bell } from '@phosphor-icons/react';
import { relativeTime } from '../../../lib/relativeTime';
import { useHistoryCatalog, type DeviceSummary } from '../api/devicePage';
import { deviceSections, findSpec, type GraphSpec } from '../lib/specs';
import { useGraphRange } from '../lib/range';
import { fmtDuration, fmtValue } from '../lib/format';
import { openDevicePage, setQuery, showOnMap } from '../lib/location';
import { HistoryGraph } from './HistoryGraph';
import { Card, Detail, Empty } from './ui';
import type { Device } from '../../../types';

const pollLabel: Record<string, string> = { snmp: 'SNMP', routeros: 'RouterOS API', none: 'Ping only' };

function uptime(device: Device): string {
    if (device.uptime_seconds === null) return '-';
    const extra = device.uptime_at ? Math.max(0, (Date.now() - Date.parse(device.uptime_at)) / 1000) : 0;
    return fmtDuration(device.uptime_seconds + extra);
}

function Tile({ label, value, tone = 'text-white/85', sub }: { label: string; value: string; tone?: string; sub?: string }) {
    return (
        <div className="rounded-xl bg-white/[0.03] px-3 py-2.5 ring-1 ring-white/[0.06]">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">{label}</p>
            <p className={`mt-0.5 font-mono text-lg tabular-nums ${tone}`}>{value}</p>
            {sub && <p className="text-[10px] text-white/35">{sub}</p>}
        </div>
    );
}

const pctTone = (v: number | null) => (v === null ? 'text-white/40' : v >= 90 ? 'text-rose-300' : v >= 75 ? 'text-amber-300' : 'text-white/85');

export function OverviewTab({ device, summary }: { device: Device; summary: DeviceSummary | undefined }) {
    const { data: catalog } = useHistoryCatalog(device.id);
    // the sparklines always show the last day, whatever the Graphs tab is zoomed to
    const { range } = useGraphRange(useMemo(() => new URLSearchParams(), []));

    const sparks = useMemo(() => {
        if (!catalog) return [];
        const sections = deviceSections(catalog, null);
        const pick = (label: string, pred: (s: GraphSpec) => boolean): [string, GraphSpec] | null => {
            const s = findSpec(sections, pred);
            return s ? [label, s] : null;
        };
        const has = (m: string) => (s: GraphSpec) => s.metrics.some((x) => x.metric === m) && !s.keys;
        return [
            pick('Traffic', (s) => !!s.aggregate),
            pick('Latency', has('rtt_ms')),
            pick('Packet loss', has('loss_pct')),
            pick('CPU', has('cpu_pct')),
            pick('Memory', has('mem_used_pct')),
            pick('Temperature', has('temp_c')),
        ].filter((x): x is [string, GraphSpec] => x !== null);
    }, [catalog]);

    const wireless = device.signal_dbm !== null || device.ccq_pct !== null || device.wireless_clients !== null;

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <Tile label="Status" value={device.status} tone={device.status === 'up' ? 'text-emerald-300' : device.status === 'down' ? 'text-rose-300' : 'text-white/45'} sub={device.last_change ? `since ${relativeTime(device.last_change)}` : undefined} />
                <Tile label="Latency" value={fmtValue(device.rtt_ms, 'ms')} sub={device.loss_pct !== null ? `${device.loss_pct.toFixed(0)}% loss` : undefined} tone={(device.loss_pct ?? 0) > 0 ? 'text-amber-300' : 'text-white/85'} />
                <Tile label="CPU" value={fmtValue(device.cpu_pct, '%')} tone={pctTone(device.cpu_pct)} />
                <Tile label="Memory" value={fmtValue(device.mem_used_pct, '%')} tone={pctTone(device.mem_used_pct)} />
                <Tile label="Temperature" value={fmtValue(device.temp_c, 'C')} />
                <Tile label="Uptime" value={uptime(device)} />
                {wireless && (
                    <>
                        <Tile label="Signal" value={fmtValue(device.signal_dbm, 'dBm')} sub={device.snr_db !== null ? `SNR ${device.snr_db.toFixed(0)} dB` : undefined} />
                        <Tile label="CCQ" value={fmtValue(device.ccq_pct, '%')} />
                        <Tile label="Wireless clients" value={device.wireless_clients === null ? '-' : String(device.wireless_clients)} />
                    </>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="Identity" className="lg:col-span-2">
                    <div className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                        <Detail label="Management IP" value={device.mgmt_ip ?? 'none (static object)'} mono={device.mgmt_ip !== null} />
                        <Detail label="Vendor" value={device.vendor ?? '-'} />
                        <Detail label="Model" value={device.model ?? '-'} />
                        <Detail label="Serial" value={device.serial ?? '-'} mono />
                        <Detail
                            label="OS version"
                            value={device.os_version ? `${device.os_version}${device.latest_version && !device.up_to_date ? ` (latest ${device.latest_version})` : ''}` : '-'}
                            mono
                        />
                        <Detail label="Uptime" value={uptime(device)} mono />
                        <Detail label="Poll method" value={device.monitored ? pollLabel[device.poll_method] ?? device.poll_method : `${pollLabel[device.poll_method] ?? device.poll_method} (paused)`} />
                        <Detail label="Polled by" value={summary?.agent ? `Agent ${summary.agent.name}` : 'This server'} />
                        <Detail label="Site" value={device.site_name ?? '-'} />
                        <Detail
                            label="Parent"
                            value={
                                device.parent_device_id !== null ? (
                                    <button type="button" onClick={() => openDevicePage(device.parent_device_id as number)} className="truncate text-emerald-300/90 hover:underline">
                                        {device.parent_name ?? `device ${device.parent_device_id}`}
                                    </button>
                                ) : (
                                    '-'
                                )
                            }
                        />
                        <Detail label="Hardware" value={[device.cpu, device.arch].filter(Boolean).join(', ') || '-'} />
                        <Detail label="Interfaces" value={summary ? String(summary.interfaces) : '-'} mono />
                    </div>
                    <div className="mt-4">
                        <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">Maps</p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            {!summary ? (
                                <span className="text-xs text-white/35">...</span>
                            ) : summary.maps.length === 0 ? (
                                <span className="text-xs text-white/40">Not placed on any map.</span>
                            ) : (
                                summary.maps.map((m) => (
                                    <button
                                        key={m.id}
                                        type="button"
                                        onClick={() => showOnMap(device.id, m.id)}
                                        title="Show on this map"
                                        className="rounded-full bg-white/[0.04] px-2.5 py-0.5 text-xs text-white/70 ring-1 ring-white/10 transition-colors hover:text-white"
                                    >
                                        {m.name}
                                    </button>
                                ))
                            )}
                        </div>
                    </div>
                </Card>

                <Card title="Open alerts" right={summary && summary.open_alerts.length > 0 ? <span className="font-mono text-xs text-rose-300">{summary.open_alerts.length}</span> : undefined}>
                    {!summary ? (
                        <p className="text-xs text-white/35">Loading...</p>
                    ) : summary.open_alerts.length === 0 ? (
                        <Empty>Nothing firing for this device.</Empty>
                    ) : (
                        <ul className="space-y-2">
                            {summary.open_alerts.map((a) => (
                                <li key={a.id} className="flex gap-2 text-xs">
                                    <Bell weight="fill" className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${a.status === 'firing' ? 'text-rose-400' : 'text-amber-300'}`} />
                                    <div className="min-w-0">
                                        <p className="text-white/80">{a.message ?? a.policy_name ?? 'Alert'}</p>
                                        <p className="text-[10px] text-white/35">
                                            {a.policy_name ? `${a.policy_name} - ` : ''}
                                            {a.status === 'resolving' ? 'clearing, ' : ''}fired {relativeTime(a.fired_at)}
                                            {a.acknowledged ? ' - acknowledged' : ''}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <Card title="Last 24 hours" right={<button type="button" onClick={() => setQuery({ tab: 'graphs' }, { push: true })} className="text-xs text-emerald-300/90 hover:underline">All graphs</button>}>
                {!catalog ? (
                    <p className="text-xs text-white/35">Loading...</p>
                ) : sparks.length === 0 ? (
                    <Empty>No history recorded for this device yet.</Empty>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {sparks.map(([label, spec]) => (
                            <div key={spec.id} className="rounded-xl bg-white/[0.02] px-3 py-2 ring-1 ring-white/[0.05]">
                                <p className="mb-1 text-[10px] font-medium uppercase tracking-[0.16em] text-white/35">{label}</p>
                                <HistoryGraph deviceId={device.id} spec={spec} range={range} compact height={56} points={120} />
                            </div>
                        ))}
                    </div>
                )}
            </Card>
        </div>
    );
}
