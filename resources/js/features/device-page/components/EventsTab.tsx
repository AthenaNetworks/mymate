import { useState } from 'react';
import { ArrowCircleDown, ArrowCircleUp, ArrowClockwise, Bell, BellSlash, CaretLeft, CaretRight, CheckCircle, GitCommit, Package, Power, type Icon } from '@phosphor-icons/react';
import { useDeviceEvents, type DeviceEvent, type DeviceEventType } from '../api/devicePage';
import { setQuery } from '../lib/location';
import { relativeTime } from '../../../lib/relativeTime';
import { Empty } from './ui';
import type { Device } from '../../../types';

const TYPES: { id: DeviceEventType; label: string }[] = [
    { id: 'outage', label: 'Outages' },
    { id: 'alert', label: 'Alerts' },
    { id: 'backup', label: 'Config changes' },
    { id: 'upgrade', label: 'Upgrades' },
    { id: 'reboot', label: 'Reboots' },
];

function look(e: DeviceEvent): { icon: Icon; cls: string } {
    if (e.type === 'outage') return e.kind === 'down' ? { icon: ArrowCircleDown, cls: 'text-rose-400' } : { icon: ArrowCircleUp, cls: 'text-emerald-400' };
    if (e.type === 'alert') return e.kind === 'fired' ? { icon: Bell, cls: 'text-amber-300' } : { icon: BellSlash, cls: 'text-white/40' };
    if (e.type === 'backup') return { icon: GitCommit, cls: 'text-sky-300' };
    if (e.type === 'upgrade') {
        if (e.kind === 'failed') return { icon: Package, cls: 'text-rose-300' };
        if (e.kind === 'done') return { icon: Package, cls: 'text-violet-300' };
        if (e.kind === 'up_to_date') return { icon: CheckCircle, cls: 'text-white/40' };
        return { icon: ArrowClockwise, cls: 'text-violet-300' }; // still going
    }
    return { icon: Power, cls: 'text-white/50' };
}

const dayLabel = (iso: string) => new Date(iso).toLocaleDateString([], { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });

/** Everything that happened to the device, newest first, one page at a time. */
export function EventsTab({ device }: { device: Device }) {
    const [types, setTypes] = useState<DeviceEventType[]>([]);
    const [page, setPage] = useState(1);
    const { data, isLoading } = useDeviceEvents(device.id, page, types);

    const toggle = (t: DeviceEventType) => {
        setPage(1);
        setTypes((prev) => (prev.includes(t) ? prev.filter((x) => x !== t) : [...prev, t]));
    };

    let lastDay = '';

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-1.5">
                {TYPES.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        onClick={() => toggle(t.id)}
                        className={`rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 transition-colors duration-200 ease-fluid ${
                            types.includes(t.id) ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' : 'text-white/45 ring-white/10 hover:text-white/75'
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
                {types.length > 0 && (
                    <button type="button" onClick={() => { setTypes([]); setPage(1); }} className="px-1 text-[11px] text-white/40 hover:text-white/75">
                        Show all
                    </button>
                )}
            </div>

            {isLoading ? (
                <p className="text-sm text-white/35">Loading...</p>
            ) : !data || data.data.length === 0 ? (
                <Empty>Nothing recorded for this device yet.</Empty>
            ) : (
                <ol className="space-y-1">
                    {data.data.map((e) => {
                        const day = dayLabel(e.at);
                        const header = day !== lastDay;
                        lastDay = day;
                        const { icon: I, cls } = look(e);
                        return (
                            <li key={e.id}>
                                {header && <p className="pt-3 pb-1 text-[10px] font-medium uppercase tracking-[0.18em] text-white/30">{day}</p>}
                                <div className="flex items-start gap-3 rounded-xl bg-white/[0.02] px-3 py-2 ring-1 ring-white/[0.05]">
                                    <I weight="fill" className={`mt-0.5 h-4 w-4 shrink-0 ${cls}`} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-white/85">{e.title}</p>
                                        {e.detail && <p className="truncate text-xs text-white/45" title={e.detail}>{e.detail}</p>}
                                    </div>
                                    {e.type === 'backup' && (
                                        <button type="button" onClick={() => setQuery({ tab: 'config' }, { push: true })} className="shrink-0 text-[11px] text-emerald-300/90 hover:underline">
                                            View
                                        </button>
                                    )}
                                    <span className="shrink-0 text-right font-mono text-[11px] text-white/40" title={new Date(e.at).toLocaleString()}>
                                        {new Date(e.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        <span className="block text-[10px] text-white/25">{relativeTime(e.at)}</span>
                                    </span>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}

            {data && data.meta.total > data.meta.per_page && (
                <div className="flex items-center justify-between text-[11px] text-white/40">
                    <span className="tabular-nums">
                        {(page - 1) * data.meta.per_page + 1}-{Math.min(page * data.meta.per_page, data.meta.total)} of {data.meta.total}
                    </span>
                    <div className="flex items-center gap-1.5">
                        <button type="button" disabled={page === 1} onClick={() => setPage(page - 1)} className="rounded-md p-1 ring-1 ring-white/10 hover:bg-white/5 disabled:opacity-30">
                            <CaretLeft weight="bold" className="h-3 w-3" />
                        </button>
                        <span className="tabular-nums">{page}</span>
                        <button type="button" disabled={!data.meta.has_more} onClick={() => setPage(page + 1)} className="rounded-md p-1 ring-1 ring-white/10 hover:bg-white/5 disabled:opacity-30">
                            <CaretRight weight="bold" className="h-3 w-3" />
                        </button>
                    </div>
                </div>
            )}
            <p className="text-[11px] text-white/30">Upgrades list every attempt since upgrade history was added (older ones only kept the last outcome). Reboots go back as far as the poller has been reading uptime.</p>
        </div>
    );
}
