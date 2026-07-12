import { useSyncExternalStore } from 'react';

export type ToastTone = 'up' | 'down' | 'info';
export type Toast = { id: number; title: string; detail?: string; tone: ToastTone };

// Tiny dependency-free toast store (module singleton + useSyncExternalStore) so any
// component can push a toast and a single <Toaster/> renders the stack.
let toasts: Toast[] = [];
const listeners = new Set<() => void>();
let nextId = 1;

function emit(): void {
    listeners.forEach((l) => l());
}

export function pushToast(toast: Omit<Toast, 'id'>, ttlMs = 4500): number {
    const id = nextId++;
    toasts = [...toasts, { ...toast, id }];
    emit();
    setTimeout(() => dismissToast(id), ttlMs);
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
