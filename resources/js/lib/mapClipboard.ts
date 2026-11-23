import { useSyncExternalStore } from 'react';
import type { MapNoteSize } from '../types';

/**
 * The map's own clipboard: what "Copy" on the map holds until something is pasted. Same tiny
 * store pattern as lib/toast.ts, kept apart from shellStore because it's content, not a UI pref.
 *
 * Not the system clipboard: `navigator.clipboard` only exists on HTTPS or localhost (a LAN
 * install on plain HTTP has none) and reading it prompts for permission. Kept in localStorage
 * instead, so a copy survives a reload and can be pasted in another tab of the same browser.
 *
 * Only notes for now; `kind` leaves room for other map items later.
 */
export type ClipboardNote = {
    text: string;
    color: string | null;
    background: string | null;
    size: MapNoteSize | null;
    // Offset from the top-left of what was copied, so a group pastes in the same arrangement.
    dx: number;
    dy: number;
};

export type MapClipboard = { kind: 'notes'; items: ClipboardNote[] } | null;

/** What a note needs to be copied: its content, style and where it sits on the canvas. */
export type CopyableNote = Omit<ClipboardNote, 'dx' | 'dy'> & { x: number; y: number };

const STORAGE_KEY = 'mymate.clipboard.v1';
const SIZES: readonly string[] = ['sm', 'md', 'lg'];

const listeners = new Set<() => void>();
// Read at the bottom of the module, once parse() and its helpers are defined.
let current: MapClipboard = null;

/** Group-relative clipboard items, offset from the copied notes' top-left corner. */
export function toClipboardNotes(notes: CopyableNote[]): ClipboardNote[] {
    const minX = Math.min(...notes.map((n) => n.x));
    const minY = Math.min(...notes.map((n) => n.y));
    return notes.map((n) => ({ text: n.text, color: n.color, background: n.background, size: n.size, dx: n.x - minX, dy: n.y - minY }));
}

export function copyNotesToClipboard(notes: CopyableNote[]): void {
    if (notes.length === 0) return;
    current = { kind: 'notes', items: toClipboardNotes(notes) };
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(current));
    } catch {
        // Storage off or full (private window): the copy still works in this tab.
    }
    emit();
}

export function getMapClipboard(): MapClipboard {
    return current;
}

export function useMapClipboard(): MapClipboard {
    return useSyncExternalStore(subscribe, getMapClipboard);
}

function emit(): void {
    listeners.forEach((l) => l());
}

function subscribe(cb: () => void): () => void {
    listeners.add(cb);
    return () => listeners.delete(cb);
}

function load(): MapClipboard {
    try {
        return parse(window.localStorage.getItem(STORAGE_KEY));
    } catch {
        return null;
    }
}

const str = (v: unknown): string | null => (typeof v === 'string' ? v : null);
const num = (v: unknown): number => (typeof v === 'number' && Number.isFinite(v) ? v : 0);

/** Storage is outside our control (old shape, another version, hand edits): rebuild it field by field. */
function parse(raw: string | null): MapClipboard {
    if (raw === null) return null;
    try {
        const v: unknown = JSON.parse(raw);
        if (typeof v !== 'object' || v === null || (v as { kind?: unknown }).kind !== 'notes') return null;
        const items = (v as { items?: unknown }).items;
        if (!Array.isArray(items)) return null;
        const notes = items.flatMap((i): ClipboardNote[] => {
            if (typeof i !== 'object' || i === null || typeof i.text !== 'string') return [];
            const size = str(i.size);
            return [{
                text: i.text,
                color: str(i.color),
                background: str(i.background),
                size: size !== null && SIZES.includes(size) ? (size as MapNoteSize) : null,
                dx: num(i.dx),
                dy: num(i.dy),
            }];
        });
        return notes.length > 0 ? { kind: 'notes', items: notes } : null;
    } catch {
        return null;
    }
}

// What was copied before a reload. Here, not at the `let` above: the `const` helpers parse()
// uses don't exist until their lines have run, and parse() would swallow that as "nothing".
current = load();

// A copy made in another tab shows up here too (the event only fires in the *other* tabs).
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY) return;
        current = parse(e.newValue);
        emit();
    });
}
