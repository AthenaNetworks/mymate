import { MapTrifold, SquaresFour, ListDashes, MagnifyingGlass, Warning, Bell, GearSix, DownloadSimple, X, type Icon } from '@phosphor-icons/react';
import { useView, setView, useNavOpen, setNavOpen, type View } from '../../lib/shellStore';
import { MapLegend } from './MapLegend';

const ITEMS: { id: View; label: string; icon: Icon }[] = [
    { id: 'map', label: 'Map', icon: MapTrifold },
    { id: 'dashboard', label: 'Dashboard', icon: SquaresFour },
    { id: 'devices', label: 'Devices', icon: ListDashes },
    { id: 'discovery', label: 'Discovery', icon: MagnifyingGlass },
    { id: 'outages', label: 'Outages', icon: Warning },
    { id: 'alerts', label: 'Alerts', icon: Bell },
    { id: 'import', label: 'Import', icon: DownloadSimple },
    { id: 'settings', label: 'Settings', icon: GearSix },
];

/** The nav body - shared by the desktop rail and the mobile drawer so the two stay
 *  in lock-step. Tapping an item navigates and closes the drawer (a no-op at lg+). */
function NavContent({ outageCount }: { outageCount: number }) {
    const view = useView();

    return (
        <>
            <p className="px-2 pt-1 text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Workspace</p>

            <ul className="space-y-0.5">
                {ITEMS.map((it) => {
                    const active = view === it.id;
                    const Icon = it.icon;
                    return (
                        <li key={it.id}>
                            <button
                                onClick={() => {
                                    setView(it.id);
                                    setNavOpen(false);
                                }}
                                className={`group flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm transition-all duration-300 ease-fluid ${
                                    active
                                        ? 'bg-white/[0.06] text-white ring-1 ring-white/10'
                                        : 'text-white/55 hover:bg-white/[0.03] hover:text-white/85'
                                }`}
                            >
                                <Icon
                                    weight={active ? 'fill' : 'light'}
                                    className={`h-[18px] w-[18px] ${active ? 'text-emerald-300' : ''}`}
                                />
                                <span className="flex-1 text-left">{it.label}</span>
                                {it.id === 'outages' && outageCount > 0 ? (
                                    <span className="rounded-full bg-amber-500/20 px-1.5 text-[10px] font-semibold tabular-nums text-amber-300 ring-1 ring-amber-400/20">
                                        {outageCount}
                                    </span>
                                ) : null}
                            </button>
                        </li>
                    );
                })}
            </ul>

            <div className="mt-auto space-y-4">
                <MapLegend />
                <div className="rounded-xl bg-white/[0.02] p-3 font-mono text-[10px] leading-relaxed text-white/30 ring-1 ring-white/[0.06]">
                    <div>server - my-mate</div>
                    <div>fping 5s - snmp 12s</div>
                    <div>my-mate v1.0.0</div>
                </div>
            </div>
        </>
    );
}

/** Left workspace rail - switches the active view; legend + engine footer pinned to the bottom.
 *  Responsive: a static column at `lg`+, a hamburger-triggered off-canvas drawer below.
 *  `outageCount` is the live number of ongoing outages (badge), passed from AppShell. */
export function NavRail({ outageCount = 0 }: { outageCount?: number }) {
    const navOpen = useNavOpen();

    return (
        <>
            {/* Desktop / large screens: a static column in the shell row. */}
            <nav className="z-10 hidden w-52 shrink-0 flex-col gap-6 border-r border-white/10 bg-white/[0.02] p-3 backdrop-blur-2xl lg:flex">
                <NavContent outageCount={outageCount} />
            </nav>

            {/* Phone / tablet: an off-canvas drawer (below lg) that slides in over the map.
                Always mounted so it can animate; pointer-events gated while closed. */}
            <div
                className={`fixed inset-0 z-40 lg:hidden ${navOpen ? '' : 'pointer-events-none'}`}
                aria-hidden={!navOpen}
            >
                <div
                    className={`absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity duration-300 ease-fluid ${
                        navOpen ? 'opacity-100' : 'opacity-0'
                    }`}
                    onClick={() => setNavOpen(false)}
                />
                <nav
                    className={`absolute inset-y-0 left-0 flex w-64 max-w-[80vw] flex-col gap-6 border-r border-white/10 bg-[#0d0d11]/95 p-3 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] backdrop-blur-2xl transition-transform duration-300 ease-fluid ${
                        navOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
                >
                    <button
                        onClick={() => setNavOpen(false)}
                        title="Close menu"
                        className="absolute right-2 top-2 rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                    <NavContent outageCount={outageCount} />
                </nav>
            </div>
        </>
    );
}
