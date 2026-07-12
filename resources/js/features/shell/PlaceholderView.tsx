import type { Icon } from '@phosphor-icons/react';

/** Styled empty-state for nav surfaces not yet built (Outages / Alerts / Settings). */
export function PlaceholderView({ icon: Icon, title, subtitle }: { icon: Icon; title: string; subtitle: string }) {
    return (
        <div className="grid h-full place-items-center p-8">
            <div className="animate-rise flex max-w-sm flex-col items-center text-center">
                <span className="mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-white/[0.04] text-white/40 ring-1 ring-white/10">
                    <Icon weight="light" className="h-7 w-7" />
                </span>
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-emerald-300/70">Coming soon</p>
                <h2 className="mt-2 text-lg font-bold tracking-tight text-white">{title}</h2>
                <p className="mt-1.5 text-sm text-white/45">{subtitle}</p>
            </div>
        </div>
    );
}
