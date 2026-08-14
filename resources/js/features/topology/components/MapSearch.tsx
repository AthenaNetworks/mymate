import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { MagnifyingGlass, X } from '@phosphor-icons/react';
import { StatusDot } from '../../../components/StatusDot';
import type { Device } from '../../../types';

/**
 * Find-a-device box for the map toolbar. React Flow has no built-in search - this filters
 * the current map\'s devices client-side and hands the picked id back to the caller, which
 * pans/zooms the canvas via `fitView({ nodes: [{ id }] })`.
 *
 * Two renders share one filter/keyboard state: a compact inline pill (`sm:` and up, sits
 * next to the map switcher) and a small icon trigger that opens a full-width overlay sheet
 * (below `sm`) - an always-expanded input there would collide with the edge-style/Tidy
 * layout controls pinned to the top-right on a narrow screen.
 */
export function MapSearch({ devices, onSelect }: { devices: Device[]; onSelect: (deviceId: number) => void }) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [highlight, setHighlight] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const mobileInputRef = useRef<HTMLInputElement>(null);

    const query = q.trim().toLowerCase();
    const matches = useMemo(
        () => (query ? devices.filter((d) => d.name.toLowerCase().includes(query) || d.mgmt_ip.includes(query)).slice(0, 8) : []),
        [devices, query],
    );

    const pick = (d: Device) => {
        onSelect(d.id);
        setQ(d.name);
        setOpen(false);
        setMobileOpen(false);
        inputRef.current?.blur();
        mobileInputRef.current?.blur();
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (matches.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlight((h) => (h + 1) % matches.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlight((h) => (h - 1 + matches.length) % matches.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            pick(matches[highlight]);
        } else if (e.key === 'Escape') {
            setOpen(false);
            setMobileOpen(false);
            inputRef.current?.blur();
            mobileInputRef.current?.blur();
        }
    };

    const matchList = (itemClassName: string) =>
        matches.length === 0 ? (
            <p className="px-2.5 py-2 text-center text-[11px] text-white/35">No devices match "{q}".</p>
        ) : (
            matches.map((d, i) => (
                <button
                    key={d.id}
                    onClick={() => pick(d)}
                    onMouseEnter={() => setHighlight(i)}
                    className={`flex w-full items-center gap-2 rounded-xl text-left transition-colors duration-150 ${itemClassName} ${
                        i === highlight ? 'bg-white/10 text-white' : 'text-white/75'
                    }`}
                >
                    <StatusDot status={d.status} />
                    <span className="min-w-0 flex-1 truncate">{d.name}</span>
                    <span className="shrink-0 font-mono text-[10px] text-white/35">{d.mgmt_ip}</span>
                </button>
            ))
        );

    return (
        <>
            {/* Compact inline pill - sm and up. */}
            <div className="relative hidden sm:block">
                <div className="flex items-center gap-1.5 rounded-full bg-surface/80 px-3 py-1.5 ring-1 ring-white/10 backdrop-blur-xl">
                    <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/35" />
                    <input
                        ref={inputRef}
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setOpen(true);
                            setHighlight(0);
                        }}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKeyDown}
                        placeholder="Find a device..."
                        className="w-32 bg-transparent text-[11px] text-white placeholder:text-white/30 outline-none transition-[width] duration-200 focus:w-48"
                    />
                    {q && (
                        <button
                            onClick={() => {
                                setQ('');
                                setOpen(false);
                            }}
                            className="shrink-0 text-white/30 transition-colors duration-150 hover:text-white/70"
                        >
                            <X weight="bold" className="h-3 w-3" />
                        </button>
                    )}
                </div>

                {open && query && (
                    <>
                        {/* Click-away - closes the dropdown on any outside click. */}
                        <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                        <div className="animate-rise absolute left-0 top-full z-20 mt-2 w-56 overflow-hidden rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                            {matchList('px-2.5 py-1.5 text-xs')}
                        </div>
                    </>
                )}
            </div>

            {/* Icon-only trigger - below sm, opens a full-width overlay sheet instead of an
                inline input, so it never fights the edge-style/Tidy layout controls for room. */}
            <button
                onClick={() => setMobileOpen(true)}
                title="Find a device"
                className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-surface/80 text-white/55 ring-1 ring-white/10 backdrop-blur-xl transition-colors duration-200 hover:text-white sm:hidden"
            >
                <MagnifyingGlass weight="bold" className="h-4 w-4" />
            </button>

            {mobileOpen && (
                // top-14 keeps the sheet clear of the h-14 global TopBar - a plain `inset-0`
                // here rendered the panel\'s `mt-3` right under the true viewport top, behind/
                // colliding with the header instead of appearing near the icon you tapped.
                <div className="fixed inset-x-0 bottom-0 top-14 z-30 bg-black/60 backdrop-blur-sm sm:hidden" onClick={() => setMobileOpen(false)}>
                    <div
                        className="animate-rise mx-3 mt-3 overflow-hidden rounded-2xl bg-surface/95 p-2 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center gap-2 rounded-xl bg-white/[0.04] px-3 py-2.5 ring-1 ring-white/10">
                            <MagnifyingGlass weight="bold" className="h-4 w-4 shrink-0 text-white/40" />
                            <input
                                ref={mobileInputRef}
                                autoFocus
                                value={q}
                                onChange={(e) => {
                                    setQ(e.target.value);
                                    setHighlight(0);
                                }}
                                onKeyDown={onKeyDown}
                                placeholder="Find a device..."
                                className="min-w-0 flex-1 bg-transparent text-sm text-white placeholder:text-white/30 outline-none"
                            />
                            <button onClick={() => setMobileOpen(false)} className="shrink-0 text-white/40 hover:text-white/80">
                                <X weight="bold" className="h-4 w-4" />
                            </button>
                        </div>
                        {query && <div className="mt-2 max-h-[50vh] space-y-0.5 overflow-y-auto">{matchList('px-3 py-2.5 text-sm')}</div>}
                    </div>
                </div>
            )}
        </>
    );
}
