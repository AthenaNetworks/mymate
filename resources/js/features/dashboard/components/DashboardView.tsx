import { useEffect, useMemo, useRef, useState } from 'react';
import { CaretLeft, CaretRight, MagnifyingGlass, Pause, Play, SlidersHorizontal, SquaresFour } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import {
    setDashboardAll,
    setDashboardCycleS,
    toggleDashboardId,
    useDashboardAll,
    useDashboardCycleS,
    useDashboardIds,
} from '../../../lib/shellStore';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceCard } from './DeviceCard';
import type { Device, DeviceStatus } from '../../../types';

// Card footprint (px) used to compute how many fit per page. Keep in step with DeviceCard.
const CARD_W = 248;
const CARD_H = 132;
const GAP = 16;

// Down first (never hidden behind the rotation), then unknown, then up; name within.
const STATUS_RANK: Record<DeviceStatus, number> = { down: 0, unknown: 1, up: 2 };

function chunk<T>(items: T[], size: number): T[][] {
    if (size <= 0) return items.length ? [items] : [];
    const out: T[][] = [];
    for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
    return out;
}

/** The searchable selection editor (which devices the grid shows). */
function SelectionPanel({ devices }: { devices: Device[] }) {
    const all = useDashboardAll();
    const ids = useDashboardIds();
    const cycleS = useDashboardCycleS();
    const [q, setQ] = useState('');
    const query = q.trim().toLowerCase();
    const list = query ? devices.filter((d) => d.name.toLowerCase().includes(query) || d.mgmt_ip.includes(query)) : devices;

    return (
        <div className="space-y-3 rounded-2xl bg-white/[0.03] p-4 ring-1 ring-white/10">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <button
                    onClick={() => setDashboardAll(!all)}
                    className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 transition-colors duration-200 ease-fluid ${
                        all ? 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/30' : 'text-white/55 ring-white/10 hover:text-white/85'
                    }`}
                >
                    <span className={`h-2 w-2 rounded-full ${all ? 'bg-emerald-400' : 'bg-white/30'}`} />
                    All devices
                </button>
                <label className="flex items-center gap-2 text-xs text-white/55">
                    Auto-cycle
                    <input
                        type="number"
                        min={2}
                        max={120}
                        value={cycleS}
                        onChange={(e) => setDashboardCycleS(Number(e.target.value))}
                        className="w-16 rounded-lg bg-white/[0.04] px-2 py-1 text-right font-mono text-xs text-white ring-1 ring-white/10 outline-none focus:ring-emerald-400/50"
                    />
                    s
                </label>
            </div>

            <div className="relative">
                <MagnifyingGlass weight="bold" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/30" />
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search devices..."
                    className="w-full rounded-xl bg-white/[0.03] py-2 pl-9 pr-3 text-sm text-white ring-1 ring-white/10 outline-none transition focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/50"
                />
            </div>

            <p className="px-1 text-[11px] text-white/35">
                {all
                    ? `Showing all ${devices.length} devices. Turn "All devices" off to use the ticked selection below.`
                    : `${ids.length} selected.`}
            </p>

            <div className="max-h-56 space-y-0.5 overflow-y-auto">
                {list.map((d) => (
                    <label
                        key={d.id}
                        className="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm text-white/75 transition-colors duration-150 hover:bg-white/[0.04]"
                    >
                        <input
                            type="checkbox"
                            checked={ids.includes(d.id)}
                            onChange={() => toggleDashboardId(d.id)}
                            className="h-4 w-4 shrink-0 accent-emerald-500"
                        />
                        <StatusDot status={d.status} />
                        <span className="min-w-0 flex-1 truncate">{d.name}</span>
                        <span className="shrink-0 font-mono text-[11px] text-white/35">{d.mgmt_ip}</span>
                    </label>
                ))}
                {list.length === 0 && <p className="px-2 py-3 text-center text-xs text-white/35">No devices match "{q}".</p>}
            </div>
        </div>
    );
}

export function DashboardView() {
    const { data: devices } = useDevices();
    // Live status: the handler folds DeviceStatusChanged into the useDevices() cache, so
    // cards re-render the moment a device flips up/down (resyncs on reconnect).
    useMapChannel();

    const all = useDashboardAll();
    const ids = useDashboardIds();
    const cycleS = useDashboardCycleS();

    const [editing, setEditing] = useState(false);
    const [page, setPage] = useState(0);
    const [paused, setPaused] = useState(false);
    const [dims, setDims] = useState({ cols: 1, rows: 1 });
    const gridRef = useRef<HTMLDivElement>(null);

    // Reconcile the selection against the live list (drop ids that no longer exist),
    // then order down-first so outages are never hidden behind the rotation.
    const ordered = useMemo(() => {
        const list = devices ?? [];
        const picked = all ? list : list.filter((d) => ids.includes(d.id));
        return [...picked].sort((a, b) => STATUS_RANK[a.status] - STATUS_RANK[b.status] || a.name.localeCompare(b.name));
    }, [devices, all, ids]);

    // Measure the grid to learn how many cards fit -> page size.
    useEffect(() => {
        const el = gridRef.current;
        if (!el) return;
        const measure = () => {
            const cols = Math.max(1, Math.floor((el.clientWidth + GAP) / (CARD_W + GAP)));
            const rows = Math.max(1, Math.floor((el.clientHeight + GAP) / (CARD_H + GAP)));
            setDims((d) => (d.cols === cols && d.rows === rows ? d : { cols, rows }));
        };
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const pageSize = Math.max(1, dims.cols * dims.rows);
    const pages = useMemo(() => chunk(ordered, pageSize), [ordered, pageSize]);

    // Keep the page index in range when the set / fit changes.
    useEffect(() => {
        setPage((p) => (p >= pages.length ? 0 : p));
    }, [pages.length]);

    // Auto-cycle pages (paused on hover/focus or when only one page).
    useEffect(() => {
        if (pages.length <= 1 || paused) return;
        const id = setInterval(() => setPage((p) => (p + 1) % pages.length), cycleS * 1000);
        return () => clearInterval(id);
    }, [pages.length, paused, cycleS]);

    const current = pages[Math.min(page, Math.max(0, pages.length - 1))] ?? [];
    const downCount = ordered.filter((d) => d.status === 'down').length;

    return (
        <div className="flex h-full flex-col gap-3 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <SquaresFour weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-base font-bold tracking-tight text-white">Dashboard</h1>
                        <p className="text-xs text-white/40">
                            {ordered.length} device{ordered.length === 1 ? '' : 's'}
                            {downCount > 0 ? <span className="text-rose-300"> - {downCount} down</span> : null}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {pages.length > 1 && (
                        <div className="flex items-center gap-1 rounded-lg ring-1 ring-white/10">
                            <button
                                onClick={() => setPage((p) => (p - 1 + pages.length) % pages.length)}
                                title="Previous page"
                                className="rounded-l-lg p-2 text-white/55 transition-colors duration-200 hover:bg-white/5 hover:text-white"
                            >
                                <CaretLeft weight="bold" className="h-4 w-4" />
                            </button>
                            <span className="px-0.5 font-mono text-[11px] tabular-nums text-white/40">
                                {page + 1}/{pages.length}
                            </span>
                            <button
                                onClick={() => setPage((p) => (p + 1) % pages.length)}
                                title="Next page"
                                className="rounded-r-lg p-2 text-white/55 transition-colors duration-200 hover:bg-white/5 hover:text-white"
                            >
                                <CaretRight weight="bold" className="h-4 w-4" />
                            </button>
                        </div>
                    )}
                    {pages.length > 1 && (
                        <button
                            onClick={() => setPaused((p) => !p)}
                            title={paused ? 'Resume rotation' : 'Pause rotation'}
                            className="rounded-lg p-2 text-white/55 ring-1 ring-white/10 transition-colors duration-200 hover:bg-white/5 hover:text-white"
                        >
                            {paused ? <Play weight="fill" className="h-4 w-4" /> : <Pause weight="fill" className="h-4 w-4" />}
                        </button>
                    )}
                    <button
                        onClick={() => setEditing((e) => !e)}
                        className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 transition-colors duration-200 ease-fluid ${
                            editing ? 'bg-white/10 text-white ring-white/15' : 'text-white/60 ring-white/10 hover:text-white/90'
                        }`}
                    >
                        <SlidersHorizontal weight="bold" className="h-4 w-4" />
                        Edit selection
                    </button>
                </div>
            </div>

            {editing && <SelectionPanel devices={devices ?? []} />}

            {/* The card grid - its measured size drives the page size. */}
            <div
                ref={gridRef}
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
                className="min-h-0 flex-1"
            >
                {ordered.length === 0 ? (
                    <div className="grid h-full place-items-center text-center">
                        <div className="max-w-xs">
                            <p className="text-sm font-medium text-white/70">No devices selected</p>
                            <p className="mt-1 text-xs text-white/40">Use "Edit selection" to add devices, or turn on "All devices".</p>
                        </div>
                    </div>
                ) : (
                    <div
                        className="grid gap-4"
                        style={{
                            gridTemplateColumns: `repeat(${dims.cols}, minmax(0, 1fr))`,
                            gridAutoRows: `${CARD_H}px`,
                        }}
                    >
                        {current.map((d) => (
                            <DeviceCard key={d.id} device={d} />
                        ))}
                    </div>
                )}
            </div>

            {/* Page indicator - dots for a few pages, a counter for many. */}
            {pages.length > 1 && (
                <div className="flex shrink-0 items-center justify-center gap-2 text-[11px] text-white/40">
                    {pages.length <= 12 ? (
                        <div className="flex items-center gap-1.5">
                            {pages.map((_, i) => (
                                <button
                                    key={i}
                                    onClick={() => setPage(i)}
                                    aria-label={`Page ${i + 1}`}
                                    className={`h-1.5 rounded-full transition-all duration-300 ease-fluid ${
                                        i === page ? 'w-5 bg-emerald-400' : 'w-1.5 bg-white/20 hover:bg-white/40'
                                    }`}
                                />
                            ))}
                        </div>
                    ) : (
                        <span className="font-mono tabular-nums">
                            {page + 1} / {pages.length}
                        </span>
                    )}
                    {paused && <span className="uppercase tracking-wide text-white/30">paused</span>}
                </div>
            )}
        </div>
    );
}
