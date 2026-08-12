import { useSyncExternalStore } from 'react';

export type ToastTone = 'up' | 'down' | 'info';
export type Toast = { id: number; title: string; detail?: string; tone: ToastTone; key?: string };

// Tiny dependency-free toast store (module singleton + useSyncExternalStore) so any
// component can push a toast and a single <Toaster/> renders the stack.
let toasts: Toast[] = [];
const listeners = new Set<() => void>();
let nextId = 1;

// Backstop against a pile-up (e.g. a long-lived wallboard riding out a flap storm):
// the stack never grows past this; oldest toasts fall off first.
const MAX_TOASTS = 6;

function emit(): void {
    listeners.forEach((l) => l());
}

export function pushToast(toast: Omit<Toast, 'id'>, ttlMs?: number): number {
    const id = nextId++;
    // A keyed toast replaces its predecessor in place (same subject, e.g. one device's
    // status) instead of stacking a duplicate. The old toast's dismiss timer becomes a
    // no-op - it targets an id that no longer exists.
    const kept = toast.key ? toasts.filter((t) => t.key !== toast.key) : toasts;
    toasts = [...kept, { ...toast, id }].slice(-MAX_TOASTS);
    emit();
    // Errors ('down') stay until dismissed - they're often long and worth reading
    // (e.g. an upload/validation message); everything else auto-clears. ttlMs=0 is sticky.
    const ttl = ttlMs ?? (toast.tone === 'down' ? 0 : 4500);
    if (ttl > 0) setTimeout(() => dismissToast(id), ttl);
    return id;
}

export function dismissToast(id: number): void {
    toasts = toasts.filter((t) => t.id !== id);
    emit();
}

export function useToasts(): Toast[] {
    return useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        () => toasts,
        () => toasts,
    );
}
