import { useConnectionStatus, type ConnState } from '../features/topology/hooks/useConnectionStatus';

const styles: Record<ConnState, { dot: string; label: string }> = {
    connected: { dot: 'bg-emerald-400 shadow-[0_0_6px_2px_rgba(52,211,153,0.6)]', label: 'Live' },
    connecting: { dot: 'bg-amber-400 animate-pulse', label: 'Reconnecting...' },
    offline: { dot: 'bg-rose-500 shadow-[0_0_6px_2px_rgba(244,63,94,0.5)]', label: 'Offline' },
};

/** Reverb connection indicator - the sidebar\'s live-status chip. */
export function ConnectionStatus() {
    const s = styles[useConnectionStatus()];

    return (
        <span className="inline-flex items-center gap-2 rounded-full bg-white/5 px-3 py-1 text-[10px] font-medium uppercase tracking-[0.2em] text-white/45 ring-1 ring-white/10">
            <span className={`h-1.5 w-1.5 rounded-full ${s.dot}`} />
            {s.label}
        </span>
    );
}
