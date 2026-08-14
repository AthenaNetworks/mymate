import { useSyncExternalStore } from 'react';

/**
 * Light/dark theme (GitHub #34). The app is dark by default; the choice is stored in
 * localStorage and applied by setting `data-theme` on <html>, which flips the CSS tokens in
 * resources/css/app.css (Tailwind's `white` colour + the surface tokens). A CSP of
 * `script-src 'self'` forbids an inline pre-render script, so initTheme() runs from the app
 * entry as early as possible instead.
 */
export type Theme = 'dark' | 'light';

const KEY = 'mymate:theme';
const listeners = new Set<() => void>();

export function getTheme(): Theme {
    try {
        return localStorage.getItem(KEY) === 'light' ? 'light' : 'dark';
    } catch {
        return 'dark'; // private mode / storage blocked
    }
}

/** Apply a theme to the document: data-theme (drives the CSS tokens), the legacy `dark` class,
 *  and the mobile browser chrome colour. */
export function applyTheme(t: Theme): void {
    const root = document.documentElement;
    root.dataset.theme = t;
    root.classList.toggle('dark', t === 'dark');
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', t === 'light' ? '#f4f4f5' : '#060608');
}

export function setTheme(t: Theme): void {
    try {
        localStorage.setItem(KEY, t);
    } catch {
        // ignore - still apply for this session
    }
    applyTheme(t);
    listeners.forEach((l) => l());
}

export function toggleTheme(): void {
    setTheme(getTheme() === 'dark' ? 'light' : 'dark');
}

/** Apply the saved theme once, at boot, before React renders. */
export function initTheme(): void {
    applyTheme(getTheme());
}

function subscribe(cb: () => void): () => void {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
}

export function useTheme(): Theme {
    return useSyncExternalStore(subscribe, getTheme, () => 'dark');
}
