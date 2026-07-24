import { useDevices } from '../devices/api/getDevices';
import type { DeviceStatus } from '../../types';

const tone: Record<DeviceStatus, string> = {
    up: 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/20',
    down: 'bg-rose-500/10 text-rose-300 ring-rose-400/20',
    unknown: 'bg-white/[0.04] text-white/50 ring-white/10',
};
const dot: Record<DeviceStatus, string> = {
    up: 'bg-emerald-400',
    down: 'bg-rose-500',
    unknown: 'bg-zinc-500',
};
const short: Record<DeviceStatus, string> = { up: 'up', down: 'down', unknown: 'unk' };

/** Live up/down/unknown tallies for the top bar, derived from the device snapshot. */
export function StatusCounts() {
    const { data: devices } = useDevices();
    // Count only monitored devices. A paused/acked device (monitored=false) is deliberately not
    // being polled, so its stale status shouldn't inflate the up/down tallies - the pill reflects
    // the health of what's actually watched.
    const counts: Record<DeviceStatus, number> = { up: 0, down: 0, unknown: 0 };
    for (const d of devices ?? []) if (d.monitored) counts[d.status] += 1;

    return (
        <div className="flex items-center gap-1.5">
            {(['up', 'down', 'unknown'] as DeviceStatus[]).map((s) => (
                <span
                    key={s}
                    className={`flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium tabular-nums ring-1 sm:px-2.5 ${tone[s]}`}
                >
                    <span className={`h-1.5 w-1.5 rounded-full ${dot[s]}`} />
                    {counts[s]}
                    <span className="hidden sm:inline">{short[s]}</span>
                </span>
            ))}
        </div>
    );
}
