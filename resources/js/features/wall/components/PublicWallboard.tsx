import { useEffect, useState } from 'react';
import { ReactFlowProvider } from '@xyflow/react';
import { WallCanvas } from './WallCanvas';
import { useWallDevices, useWallMap } from '../api/wall';
import { BrandedLoader } from '../../../components/BrandedLoader';

// Live wall clock (updates each minute - a wallboard doesn't need a ticking seconds hand).
function useClock(): string {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), 30_000);
        return () => clearInterval(t);
    }, []);
    return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/**
 * The public, read-only wallboard (GitHub #15). Rendered instead of the whole app when the page
 * was opened via a /wall/{token} share link - no login, no navigation, no edit controls. Just a
 * chrome-free live map with a thin status header, on its own token-gated data.
 */
export function PublicWallboard() {
    const { data: map, isLoading, isError } = useWallMap();
    const { data: devices } = useWallDevices();
    const clock = useClock();

    if (isLoading) {
        return <BrandedLoader />;
    }

    // A revoked or deleted share still serves the shell (the token looked valid at page load) but
    // the data endpoints 404 - show a clean message rather than an empty canvas.
    if (isError || !map) {
        return (
            <div className="grid h-screen w-screen place-items-center bg-[#0a0a0d] text-center">
                <div className="max-w-sm px-6">
                    <p className="text-sm font-medium text-white/80">This wallboard link is no longer available</p>
                    <p className="mt-1.5 text-xs text-white/45">
                        The share may have been turned off or removed. Ask whoever sent it for a fresh link.
                    </p>
                </div>
            </div>
        );
    }

    const up = (devices ?? []).filter((d) => d.status === 'up').length;
    const down = (devices ?? []).filter((d) => d.status === 'down').length;
    const total = devices?.length ?? 0;

    return (
        <div className="flex h-screen w-screen flex-col bg-[#0a0a0d]">
            <header className="flex items-center justify-between gap-4 border-b border-white/10 bg-[#0d0d11]/80 px-5 py-3 backdrop-blur-xl">
                <div className="flex items-center gap-3">
                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_12px_2px_rgba(52,211,153,0.5)]" />
                    <h1 className="text-sm font-semibold tracking-tight text-white/90">{map.name}</h1>
                </div>
                <div className="flex items-center gap-4 font-mono text-[11px] tabular-nums">
                    <span className="flex items-center gap-1.5 text-emerald-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                        {up} up
                    </span>
                    <span className={`flex items-center gap-1.5 ${down > 0 ? 'text-rose-300' : 'text-white/35'}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${down > 0 ? 'bg-rose-400' : 'bg-white/25'}`} />
                        {down} down
                    </span>
                    <span className="text-white/35">{total} devices</span>
                    <span className="text-white/55">{clock}</span>
                </div>
            </header>
            <div className="relative min-h-0 flex-1">
                <ReactFlowProvider>
                    <WallCanvas />
                </ReactFlowProvider>
            </div>
        </div>
    );
}
