import type { DeviceType } from '../types';

/** Shared mono type badge (NET/RTR/SW/AP/SRV/DEV) used by the map inspector + the device list. */
const meta: Record<DeviceType, { abbr: string; tint: string }> = {
    internet: { abbr: 'NET', tint: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    router: { abbr: 'RTR', tint: 'bg-sky-500/15 text-sky-300 ring-sky-400/25' },
    switch: { abbr: 'SW', tint: 'bg-violet-500/15 text-violet-300 ring-violet-400/25' },
    ap: { abbr: 'AP', tint: 'bg-fuchsia-500/15 text-fuchsia-300 ring-fuchsia-400/25' },
    server: { abbr: 'SRV', tint: 'bg-amber-500/15 text-amber-300 ring-amber-400/25' },
    unknown: { abbr: 'DEV', tint: 'bg-white/5 text-white/40 ring-white/10' },
};

export function DeviceTypeBadge({ type, className = '' }: { type: DeviceType; className?: string }) {
    const m = meta[type] ?? meta.unknown;
    return (
        <span
            className={`grid shrink-0 place-items-center rounded-md font-mono text-[10px] font-semibold ring-1 ${m.tint} ${className}`}
        >
            {m.abbr}
        </span>
    );
}
