import { useEffect, useRef, useState } from 'react';
import { CaretLeft, CaretRight, MagnifyingGlass, Pause, Play, SlidersHorizontal, SquaresFour } from '@phosphor-icons/react';
import { useDebounced, useDeviceList, useDevicesByIds } from '../../devices/api/getDevices';
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

// Card footprint (px) used to compute how many fit per page. Keep in step with DeviceCard.
const CARD_W = 248;
const CARD_H = 132;
const GAP = 16;

// The list endpoint's caps: cards per page, and ids per request for a hand-picked selection.
const MAX_PAGE = 200;
const MAX_IDS = 500;

/** The searchable selection editor (which devices the grid shows). */
function SelectionPanel({ total }: { total: number | null }) {
    const all = useDashboardAll();
    const ids = useDashboardIds();
    const cycleS = useDashboardCycleS();
    const [q, setQ] = useState('');
    const query = useDebounced(q.trim());
    // Server-side search (GitHub #22). With no search the ticked devices lead the list.
    const { data: page } = useDeviceList({ q: query || undefined, per_page: 100, fields: 'summary' });
    const { data: chosen } = useDevicesByIds(query ? [] : ids.slice(0, MAX_IDS));
    const lead = query ? [] : (chosen ?? []);
    const leadIds = new Set(lead.map((d) => d.id));
    const list = [...lead, ...(page?.data ?? []).filter((d) => !leadIds.has(d.id))];

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
                    ? `Showing all ${total ?? 0} devices. Turn "All devices" off to use the ticked selection below.`
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
    // Live status: the handler folds DeviceStatusChanged into every cached device query, so
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

    // The grid pages on the server (GitHub #22): one request per screenful, sorted down-first
    // (then unknown, then up, by name) so outages are never hidden behind the rotation.
    const pageSize = Math.min(MAX_PAGE, Math.max(1, dims.cols * dims.rows));
    const nothingPicked = !all && ids.length === 0;
    const scope = all ? {} : { ids: ids.slice(0, MAX_IDS) };
    const { data } = useDeviceList({ ...scope, sort: 'status', page: page + 1, per_page: pageSize }, { enabled: !nothingPicked, refetchInterval: 30_000 });
    const { data: downPage } = useDeviceList({ ...scope, status: 'down', per_page: 1, fields: 'summary' }, { enabled: !nothingPicked, refetchInterval: 30_000 });
    const total = nothingPicked ? 0 : (data?.meta.total ?? 0);
    const pageCount = nothingPicked ? 0 : (data?.meta.last_page ?? 0);
    const current = nothingPicked ? [] : (data?.data ?? []);
    const downCount = nothingPicked ? 0 : (downPage?.meta.total ?? 0);

    // Keep the page index in range when the set / fit changes.
    useEffect(() => {
        setPage((p) => (p >= pageCount ? 0 : p));
    }, [pageCount]);

    // Auto-cycle pages (paused on hover/focus or when only one page).
    useEffect(() => {
        if (pageCount <= 1 || paused) return;
        const id = setInterval(() => setPage((p) => (p + 1) % pageCount), cycleS * 1000);
        return () => clearInterval(id);
    }, [pageCount, paused, cycleS]);

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
                            {total} device{total === 1 ? '' : 's'}
                            {downCount > 0 ? <span className="text-rose-300"> - {downCount} down</span> : null}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {pageCount > 1 && (
                        <div className="flex items-center gap-1 rounded-lg ring-1 ring-white/10">
                            <button
                                onClick={() => setPage((p) => (p - 1 + pageCount) % pageCount)}
                                title="Previous page"
                                className="rounded-l-lg p-2 text-white/55 transition-colors duration-200 hover:bg-white/5 hover:text-white"
                            >
                                <CaretLeft weight="bold" className="h-4 w-4" />
                            </button>
                            <span className="px-0.5 font-mono text-[11px] tabular-nums text-white/40">
                                {page + 1}/{pageCount}
                            </span>
                            <button
                                onClick={() => setPage((p) => (p + 1) % pageCount)}
                                title="Next page"
                                className="rounded-r-lg p-2 text-white/55 transition-colors duration-200 hover:bg-white/5 hover:text-white"
                            >
                                <CaretRight weight="bold" className="h-4 w-4" />
                            </button>
                        </div>
                    )}
                    {pageCount > 1 && (
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

            {editing && <SelectionPanel total={all ? total : null} />}

            {/* The card grid - its measured size drives the page size. */}
            <div
                ref={gridRef}
                onMouseEnter={() => setPaused(true)}
                onMouseLeave={() => setPaused(false)}
                className="min-h-0 flex-1"
            >
                {total === 0 ? (
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
            {pageCount > 1 && (
                <div className="flex shrink-0 items-center justify-center gap-2 text-[11px] text-white/40">
                    {pageCount <= 12 ? (
                        <div className="flex items-center gap-1.5">
                            {Array.from({ length: pageCount }, (_, i) => (
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
                            {page + 1} / {pageCount}
                        </span>
                    )}
                    {paused && <span className="uppercase tracking-wide text-white/30">paused</span>}
                </div>
            )}
        </div>
    );
}
