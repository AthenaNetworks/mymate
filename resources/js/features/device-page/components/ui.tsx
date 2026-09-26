import type { ReactNode } from 'react';

// Small layout pieces shared by the device page tabs, in the inspector's look.

export function Card({ title, right, children, className = '' }: { title?: string; right?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={`rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06] ${className}`}>
            {(title || right) && (
                <div className="mb-3 flex items-center justify-between gap-2">
                    {title ? <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">{title}</p> : <span />}
                    {right}
                </div>
            )}
            {children}
        </section>
    );
}

export function Detail({ label, value, mono, title }: { label: string; value: ReactNode; mono?: boolean; title?: string }) {
    return (
        <div className="min-w-0" title={title}>
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">{label}</p>
            <div className={`mt-0.5 truncate text-sm text-white/85 ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</div>
        </div>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">{children}</p>;
}

export const actionBtn =
    'flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-2.5 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white disabled:cursor-not-allowed disabled:opacity-40';
