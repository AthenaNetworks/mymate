import { useSyncExternalStore } from 'react';

/**
 * Light/dark theme (GitHub #34). The app is dark by default; the choice is stored in
 * localStorage and applied by setting `data-theme` on <html>, which flips the CSS tokens in
 * resources/css/app.css (Tailwind's `white` colour + the surface tokens). A CSP of
 * `script-src 'self'` forbids an inline pre-render script, so initTheme() runs from the app
 * entry as early as possible instead.
 *
 * Contrast is a second, independent axis: `data-contrast="high"` on <html> turns on the
 * high-contrast rules in app.css (pure black/white surfaces, a floor on muted text, larger
 * small print, stronger borders) on top of whichever theme is active.
 */
export type Theme = 'dark' | 'light';
export type Contrast = 'standard' | 'high';

const KEY = 'mymate:theme';
const CONTRAST_KEY = 'mymate:contrast';
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
    const high = getContrast() === 'high';
    const chrome = t === 'light' ? (high ? '#ffffff' : '#f4f4f5') : high ? '#000000' : '#060608';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', chrome);
}

export function getContrast(): Contrast {
    try {
        return localStorage.getItem(CONTRAST_KEY) === 'high' ? 'high' : 'standard';
    } catch {
        return 'standard'; // private mode / storage blocked
    }
}

/** Apply the contrast level to the document (drives the `[data-contrast]` rules in app.css). */
export function applyContrast(c: Contrast): void {
    document.documentElement.dataset.contrast = c;
    applyTheme(getTheme()); // refresh the mobile browser chrome colour
}

export function setContrast(c: Contrast): void {
    try {
        localStorage.setItem(CONTRAST_KEY, c);
    } catch {
        // ignore - still apply for this session
    }
    applyContrast(c);
    listeners.forEach((l) => l());
}

export function toggleContrast(): void {
    setContrast(getContrast() === 'high' ? 'standard' : 'high');
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
    applyContrast(getContrast());
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

export function useContrast(): Contrast {
    return useSyncExternalStore(subscribe, getContrast, () => 'standard');
}
