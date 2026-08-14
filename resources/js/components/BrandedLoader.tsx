import { Logomark } from './Logomark';

/** Full-screen branded loading state - shown while the session resolves (and while the
 *  demo viewer auto-logs-in). Displays the logo so visitors immediately know the product. */
export function BrandedLoader({ label = 'Loading...' }: { label?: string }) {
    return (
        <div className="relative grid min-h-screen place-items-center overflow-hidden bg-surface-deep text-zinc-100">
            <div
                aria-hidden
                className="pointer-events-none fixed inset-0"
                style={{
                    background:
                        'radial-gradient(55rem 55rem at 10% -15%, rgba(16,185,129,0.12), transparent 60%),' +
                        'radial-gradient(45rem 45rem at 105% 115%, rgba(99,102,241,0.10), transparent 55%)',
                }}
            />

            <div className="relative flex flex-col items-center gap-5">
                <div className="relative">
                    <div className="absolute inset-0 -z-10 animate-ping rounded-2xl bg-emerald-500/20" style={{ animationDuration: '2.4s' }} />
                    <Logomark size={64} className="shadow-[0_10px_30px_-8px_rgba(16,185,129,0.7)]" />
                </div>

                <div className="text-center">
                    <div className="text-xl font-bold tracking-tight text-white">My Mate</div>
                    <div className="mt-1 text-[10px] font-medium uppercase tracking-[0.25em] text-white/35">Network Mate</div>
                </div>

                <div className="flex items-center gap-1.5" role="status" aria-label={label}>
                    {[0, 1, 2].map((i) => (
                        <span
                            key={i}
                            className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-400/70"
                            style={{ animationDelay: `${i * 0.15}s`, animationDuration: '0.9s' }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
