import type { DeviceStatus } from '../types';

// Status as colour + glow. Colour is never the only signal (paired with labels elsewhere)
// per the accessibility rule, but the glow gives a premium, glanceable NOC feel.
const styles: Record<DeviceStatus, string> = {
    up: 'bg-emerald-400 shadow-[0_0_8px_2px_rgba(52,211,153,0.55)]',
    down: 'bg-rose-500 shadow-[0_0_8px_2px_rgba(244,63,94,0.55)]',
    unknown: 'bg-zinc-600',
};

export function StatusDot({ status, className = '' }: { status: DeviceStatus; className?: string }) {
    return (
        <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${styles[status] ?? styles.unknown} ${className}`} />
    );
}
