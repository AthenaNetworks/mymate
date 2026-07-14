import { ArrowUp, ArrowDown, Info, X } from '@phosphor-icons/react';
import { dismissToast, useToasts, type ToastTone } from '../lib/toast';

const tones: Record<ToastTone, { icon: typeof Info; chip: string }> = {
    up: { icon: ArrowUp, chip: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    down: { icon: ArrowDown, chip: 'bg-rose-500/15 text-rose-300 ring-rose-400/25' },
    info: { icon: Info, chip: 'bg-white/5 text-white/50 ring-white/10' },
};

/** Bottom-right toast stack (up/down flips, etc.). Fixed element - blur is fine here. */
export function Toaster() {
    const toasts = useToasts();

    return (
        <div className="pointer-events-none fixed bottom-5 right-5 z-[60] flex w-96 max-w-[calc(100vw-2.5rem)] flex-col gap-2">
            {toasts.map((t) => {
                const tone = tones[t.tone];
                const Icon = tone.icon;
                return (
                    <div
                        key={t.id}
                        className="animate-toast pointer-events-auto flex items-start gap-3 rounded-2xl bg-[#0d0d11]/95 p-3 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
                    >
                        <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ring-1 ${tone.chip}`}>
                            <Icon weight="bold" className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 flex-1 pt-0.5">
                            <p className="text-sm font-medium text-white/90 [overflow-wrap:anywhere]">{t.title}</p>
                            {t.detail && (
                                <p className="mt-0.5 text-xs leading-relaxed text-white/45 [overflow-wrap:anywhere]">{t.detail}</p>
                            )}
                        </div>
                        <button
                            onClick={() => dismissToast(t.id)}
                            className="rounded-md p-0.5 text-white/30 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/70"
                        >
                            <X weight="bold" className="h-3.5 w-3.5" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
