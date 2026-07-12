import { useEffect, useState } from 'react';
import { List, MagnifyingGlass, SignOut, Television } from '@phosphor-icons/react';
import { useConnectionStatus } from '../topology/hooks/useConnectionStatus';
import { StatusCounts } from './StatusCounts';
import { setView, setNavOpen, setWallboard } from '../../lib/shellStore';
import { useCurrentUser, useLogout } from '../auth/api/auth';
import { Logomark } from '../../components/Logomark';

const PING_S = 5; // mirrors config/mymate.php (MYMATE_PING_INTERVAL) - cosmetic label

const conn: Record<string, { dot: string; label: string }> = {
    connected: { dot: 'bg-emerald-400 shadow-[0_0_6px_2px_rgba(52,211,153,0.6)]', label: 'polling' },
    connecting: { dot: 'bg-amber-400 animate-pulse', label: 'reconnecting' },
    offline: { dot: 'bg-rose-500 shadow-[0_0_6px_2px_rgba(244,63,94,0.5)]', label: 'offline' },
};

function useClock(): string {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(id);
    }, []);
    return now.toLocaleTimeString('en-GB', { hour12: false });
}

/** Full-width NOC status bar: brand - breadcrumb - live status tallies - engine state + clock - Discover. */
export function TopBar() {
    const state = useConnectionStatus();
    const clock = useClock();
    const c = conn[state] ?? conn.offline;
    const { data: user } = useCurrentUser();
    const logout = useLogout();

    // Enter wallboard + go fullscreen (needs this user gesture). AppShell syncs the flag
    // back off when fullscreen exits (Esc), so this only ever turns it on.
    const enterWallboard = () => {
        setWallboard(true);
        document.documentElement.requestFullscreen?.().catch(() => {});
    };

    return (
        <header className="z-20 flex h-14 shrink-0 items-center gap-3 border-b border-white/10 bg-white/[0.02] px-3 backdrop-blur-2xl sm:gap-5 sm:px-4">
            {/* Hamburger - opens the off-canvas nav drawer below lg. */}
            <button
                onClick={() => setNavOpen(true)}
                title="Menu"
                className="-ml-1 rounded-lg p-1.5 text-white/55 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white lg:hidden"
            >
                <List weight="bold" className="h-5 w-5" />
            </button>

            <div className="flex items-center gap-2.5">
                <Logomark size={32} className="shadow-[0_6px_18px_-6px_rgba(16,185,129,0.7)]" />

                <div className="leading-none">
                    <div className="text-sm font-bold tracking-tight text-white">My Mate</div>
                    <div className="mt-0.5 text-[9px] font-medium uppercase tracking-[0.2em] text-white/30">Network Mate</div>
                </div>
            </div>

            <div className="hidden items-center gap-1.5 text-xs text-white/40 sm:flex">
                <span>Topology</span>
                <span className="text-white/20">/</span>
                <span className="text-white/70">Default Map</span>
            </div>

            <div className="flex-1" />

            <StatusCounts />

            <div className="hidden items-center gap-1.5 font-mono text-[11px] tabular-nums text-white/45 md:flex">
                <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} />
                <span>{c.label}</span>
                <span className="text-white/20">-</span>
                <span>{PING_S}s</span>
                <span className="text-white/20">-</span>
                <span className="text-white/70">{clock}</span>
            </div>

            {/* Wallboard / TV mode - chrome-free fullscreen for a NOC big screen. */}
            <button
                onClick={enterWallboard}
                title="Wallboard mode"
                className="hidden rounded-lg p-1.5 text-white/45 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 sm:block"
            >
                <Television weight="bold" className="h-4 w-4" />
            </button>

            <button
                onClick={() => setView('discovery')}
                title="Discover"
                className="group flex items-center gap-2 rounded-full bg-emerald-500 py-1.5 pl-2 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] sm:pl-4"
            >
                <span className="hidden sm:inline">Discover</span>
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-950/15 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5">
                    <MagnifyingGlass weight="bold" className="h-3.5 w-3.5" />
                </span>
            </button>

            {/* Current operator + sign out. */}
            <div className="flex items-center gap-2 border-l border-white/10 pl-3">
                {user && <span className="hidden max-w-[10rem] truncate text-xs text-white/55 lg:inline">{user.name}</span>}
                <button
                    onClick={() => logout.mutate()}
                    disabled={logout.isPending}
                    title="Sign out"
                    className="rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 disabled:opacity-50"
                >
                    <SignOut weight="bold" className="h-4 w-4" />
                </button>
            </div>
        </header>
    );
}
