import type { ReactNode } from 'react';
import { CircleNotch, Play, Stop } from '@phosphor-icons/react';
import type { RunStatus } from '../api/tools';

// Shared control styling, kept in step with the rest of the console (DeviceInspector / TraceModal).
export const inputCls =
    'w-full rounded-lg bg-white/[0.04] px-3 py-2 text-sm text-white placeholder-white/25 ring-1 ring-white/10 transition-colors duration-200 focus:outline-none focus:ring-emerald-400/40';

export const ctrlBtn =
    'inline-flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-3 py-2 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white disabled:cursor-not-allowed disabled:opacity-40';

export const primaryBtn =
    'inline-flex items-center justify-center gap-1.5 rounded-lg bg-emerald-500/15 px-3.5 py-2 text-xs font-semibold text-emerald-200 ring-1 ring-emerald-400/25 transition-all duration-300 ease-fluid hover:bg-emerald-500/25 hover:text-emerald-100 disabled:cursor-not-allowed disabled:opacity-40';

export const th = 'whitespace-nowrap px-3 py-2 text-left text-[10px] font-medium uppercase tracking-[0.16em] text-white/35';
export const td = 'whitespace-nowrap px-3 py-1.5 font-mono text-sm tabular-nums';

/** A run's live status as a coloured pill, with a spinner while it's still going. */
export function StatusPill({ status, running }: { status: RunStatus | null; running: boolean }) {
    const label = running ? 'running' : status ?? 'idle';
    const tone =
        status === 'failed'
            ? 'bg-rose-500/15 text-rose-200 ring-rose-400/25'
            : running
              ? 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/25'
              : status === 'stopped'
                ? 'bg-amber-500/15 text-amber-200 ring-amber-400/25'
                : status === 'done'
                  ? 'bg-white/[0.06] text-white/70 ring-white/10'
                  : 'bg-white/[0.04] text-white/40 ring-white/10';
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide ring-1 ${tone}`}>
            {running && <CircleNotch weight="bold" className="h-3 w-3 animate-spin" />}
            {label}
        </span>
    );
}

/** Start / Stop toggle used by every streaming tool. */
export function RunButton({
    running,
    onStart,
    onStop,
    startLabel = 'Run',
    disabled = false,
}: {
    running: boolean;
    onStart: () => void;
    onStop: () => void;
    startLabel?: string;
    disabled?: boolean;
}) {
    return running ? (
        <button onClick={onStop} className={ctrlBtn}>
            <Stop weight="fill" className="h-3.5 w-3.5" /> Stop
        </button>
    ) : (
        <button onClick={onStart} className={primaryBtn} disabled={disabled}>
            <Play weight="fill" className="h-3.5 w-3.5" /> {startLabel}
        </button>
    );
}

/** A labelled figure tile for the summary strips (loss %, avg RTT, open ports, ...). */
export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
    return (
        <div className="rounded-xl bg-white/[0.02] px-3 py-2.5 ring-1 ring-white/[0.06]">
            <div className="text-[10px] uppercase tracking-[0.16em] text-white/35">{label}</div>
            <div className={`mt-0.5 font-mono text-lg tabular-nums ${tone ?? 'text-white/85'}`}>{value}</div>
        </div>
    );
}

/** The frame every tool sits in: a titled card with a description and the tool's controls/output. */
export function ToolCard({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]">
            <h2 className="text-sm font-bold tracking-tight text-white">{title}</h2>
            <p className="mt-0.5 text-xs text-white/40">{description}</p>
            <div className="mt-4">{children}</div>
        </div>
    );
}

/** A dismissable error strip (start refused, run failed, lookup error). */
export function ErrorStrip({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl bg-rose-500/10 px-3 py-2.5 text-xs text-rose-200 ring-1 ring-rose-400/20">
            {children}
        </div>
    );
}

// Sub-ms precision only matters on the near hops; past 100 ms the decimal is noise.
export function fmtMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '-';
    return ms >= 100 ? ms.toFixed(0) : ms.toFixed(1);
}
