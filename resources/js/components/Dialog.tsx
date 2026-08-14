import { useEffect, useRef, useState, type ReactNode } from 'react';
import { X } from '@phosphor-icons/react';

/**
 * App dialog primitives - a styled replacement for the browser\'s `window.prompt` /
 * `window.confirm`, matching the Ethereal-Glass chrome used by the other dialogs
 * (backdrop blur - floating glass card - emerald primary). Escape and the backdrop
 * both dismiss; Enter submits the prompt.
 */
function ModalShell({ icon, title, onClose, children }: { icon?: ReactNode; title: string; onClose: () => void; children: ReactNode }) {
    // Escape closes, regardless of focus.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            {/* Backdrop - a fixed element, so glass blur is allowed here (never on the canvas). */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-sm rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="max-h-[85vh] overflow-y-auto rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <header className="mb-4 flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            {icon && (
                                <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                    {icon}
                                </span>
                            )}
                            <h2 className="text-base font-bold tracking-tight text-white">{title}</h2>
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-1 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                        >
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>
                    {children}
                </div>
            </div>
        </div>
    );
}

const cancelBtn = 'rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors duration-300 ease-fluid hover:text-white/90';

/** Text-input dialog - replaces `window.prompt`. */
export function PromptDialog({
    title,
    label,
    icon,
    initial = '',
    placeholder,
    confirmLabel = 'Save',
    busy = false,
    onSubmit,
    onClose,
}: {
    title: string;
    label?: string;
    icon?: ReactNode;
    initial?: string;
    placeholder?: string;
    confirmLabel?: string;
    busy?: boolean;
    onSubmit: (value: string) => void;
    onClose: () => void;
}) {
    const [value, setValue] = useState(initial);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
    }, []);

    const trimmed = value.trim();
    const canSubmit = trimmed !== '' && !busy;

    function submit() {
        if (canSubmit) onSubmit(trimmed);
    }

    return (
        <ModalShell icon={icon} title={title} onClose={onClose}>
            <div className="space-y-3">
                {label && <label className="block text-[10px] font-medium uppercase tracking-[0.16em] text-white/35">{label}</label>}
                <input
                    ref={inputRef}
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') submit();
                    }}
                    placeholder={placeholder}
                    className="w-full rounded-xl bg-white/[0.04] px-3.5 py-2.5 text-sm text-white ring-1 ring-white/10 transition-colors duration-200 ease-fluid outline-none placeholder:text-white/30 focus:ring-emerald-400/50"
                />
                <div className="flex items-center justify-end gap-2 pt-1">
                    <button onClick={onClose} className={cancelBtn}>
                        Cancel
                    </button>
                    <button
                        onClick={submit}
                        disabled={!canSubmit}
                        className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        {busy ? 'Saving...' : confirmLabel}
                    </button>
                </div>
            </div>
        </ModalShell>
    );
}

/** Confirmation dialog - replaces `window.confirm`. */
export function ConfirmDialog({
    title,
    message,
    icon,
    confirmLabel = 'Confirm',
    tone = 'default',
    busy = false,
    onConfirm,
    onClose,
}: {
    title: string;
    message: ReactNode;
    icon?: ReactNode;
    confirmLabel?: string;
    tone?: 'default' | 'danger';
    busy?: boolean;
    onConfirm: () => void;
    onClose: () => void;
}) {
    const confirmCls =
        tone === 'danger'
            ? 'bg-rose-500 text-white shadow-[0_8px_24px_-8px_rgba(244,63,94,0.6)] hover:bg-rose-400'
            : 'bg-emerald-500 text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] hover:bg-emerald-400';

    return (
        <ModalShell icon={icon} title={title} onClose={onClose}>
            <div className="space-y-4">
                <p className="text-sm leading-relaxed text-white/60">{message}</p>
                <div className="flex items-center justify-end gap-2">
                    <button onClick={onClose} className={cancelBtn}>
                        Cancel
                    </button>
                    <button
                        onClick={onConfirm}
                        disabled={busy}
                        className={`rounded-full px-5 py-2 text-sm font-semibold transition-all duration-300 ease-fluid active:scale-[0.98] disabled:opacity-40 ${confirmCls}`}
                    >
                        {busy ? 'Working...' : confirmLabel}
                    </button>
                </div>
            </div>
        </ModalShell>
    );
}
