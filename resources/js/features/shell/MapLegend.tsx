import type { DeviceStatus } from '../../types';

const dot: Record<DeviceStatus, string> = {
    up: 'bg-emerald-400',
    down: 'bg-rose-500',
    unknown: 'bg-zinc-500',
};

/**
 * Nav-rail legend: the green->yellow->red utilisation ramp (same HSL stops as
 * features/topology/lib/linkColor.ts) + the up/down/unknown status key.
 */
export function MapLegend() {
    return (
        <div className="space-y-2 px-1">
            <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">Link utilisation</p>
            <div
                className="h-1.5 w-full rounded-full"
                style={{ background: 'linear-gradient(90deg, hsl(120,70%,45%), hsl(60,70%,45%), hsl(0,70%,45%))' }}
            />
            <div className="flex justify-between font-mono text-[9px] tabular-nums text-white/30">
                <span>0%</span>
                <span>50%</span>
                <span>100%</span>
            </div>
            <div className="flex items-center gap-3 pt-1 text-[10px] text-white/45">
                {(['up', 'down', 'unknown'] as DeviceStatus[]).map((s) => (
                    <span key={s} className="flex items-center gap-1.5">
                        <span className={`h-1.5 w-1.5 rounded-full ${dot[s]}`} />
                        {s}
                    </span>
                ))}
            </div>
        </div>
    );
}
