import { useCallback, useEffect, useRef, type Dispatch, type SetStateAction } from 'react';
import { useReactFlow, type Node } from '@xyflow/react';
import { copyNotesToClipboard, getMapClipboard, toClipboardNotes, useMapClipboard, type ClipboardNote, type CopyableNote } from '../../../lib/mapClipboard';
import { pushToast } from '../../../lib/toast';
import { useCreateMapNotes, useDeleteMapNote } from '../../maps/api/maps';
import type { MapNote } from '../../../types';

type XY = { x: number; y: number };

// Note nodes on the canvas are keyed `note:<id>` (see MapCanvas).
export const NOTE_PREFIX = 'note:';
export const noteNodeId = (id: number) => `${NOTE_PREFIX}${id}`;
export const noteId = (nodeId: string) => Number(nodeId.slice(NOTE_PREFIX.length));

/** How far a duplicate lands from its original - enough to see both, close enough to read as a copy. */
const DUPLICATE_OFFSET = 24;

const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
/** Shortcut label for a menu, e.g. "⌘C" on a Mac, "Ctrl+C" elsewhere. */
export const shortcut = (key: string) => (IS_MAC ? `⌘${key}` : `Ctrl+${key}`);

const plural = (n: number) => (n === 1 ? 'note' : `${n} notes`);

function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * Copy / paste / duplicate / delete for map notes, from the right-click menus and the keyboard
 * (Ctrl/⌘ + C, V, D). The clipboard lives in lib/mapClipboard, so a copy can be pasted on any
 * map - including in another tab.
 *
 * `enabled` is admin and not in history playback: every action here is a write, apart from Copy,
 * which is pointless without a paste.
 */
export function useNoteClipboard({
    mapId,
    enabled,
    notes,
    canvasEl,
    setNodes,
}: {
    mapId: number | null;
    enabled: boolean;
    notes: MapNote[];
    canvasEl: HTMLElement | null;
    setNodes: Dispatch<SetStateAction<Node[]>>;
}) {
    const { getNodes, screenToFlowPosition } = useReactFlow();
    const clipboard = useMapClipboard();
    const createNotes = useCreateMapNotes();
    const deleteMapNote = useDeleteMapNote();

    // Last pointer position over the canvas (viewport px), so a keyboard paste lands under it.
    const pointer = useRef<XY | null>(null);
    // Nodes to select when they first appear - pasted notes arrive with the map refetch.
    const pendingSelect = useRef<Set<string>>(new Set());

    /** Content + current on-canvas position (a drag may not be saved yet) of these notes. */
    const copyable = useCallback(
        (ids: number[]): CopyableNote[] => {
            const posById = new Map(getNodes().map((n) => [n.id, n.position]));
            return ids.flatMap((id) => {
                const n = notes.find((x) => x.id === id);
                if (!n) return [];
                const p = posById.get(noteNodeId(id)) ?? { x: n.x, y: n.y };
                return [{ text: n.text, color: n.color, background: n.background, size: n.size, x: p.x, y: p.y }];
            });
        },
        [getNodes, notes],
    );

    const selectedNoteIds = useCallback(() => getNodes().filter((n) => n.type === 'note' && n.selected).map((n) => noteId(n.id)), [getNodes]);

    /** Select exactly these nodes, if they're already on the canvas. */
    const selectOnly = useCallback(
        (ids: Set<string>) =>
            setNodes((prev) => {
                if (!prev.some((n) => ids.has(n.id))) return prev;
                return prev.map((n) => {
                    const on = ids.has(n.id);
                    return n.selected === on ? n : { ...n, selected: on };
                });
            }),
        [setNodes],
    );

    const create = useCallback(
        (items: ClipboardNote[], at: XY, verb: 'paste' | 'duplicate') => {
            if (mapId === null || items.length === 0) return;
            createNotes.mutate(
                {
                    mapId,
                    notes: items.map((i) => ({ text: i.text, color: i.color, background: i.background, size: i.size, x: at.x + i.dx, y: at.y + i.dy })),
                },
                {
                    // The new notes are selected, so they can be nudged into place straight away.
                    // The refetch may land before or after this, so cover both.
                    onSuccess: (created) => {
                        const ids = new Set(created.map((c) => noteNodeId(c.id)));
                        pendingSelect.current = ids;
                        selectOnly(ids);
                    },
                    onError: () => pushToast({ title: `Couldn't ${verb} the ${items.length === 1 ? 'note' : 'notes'}`, tone: 'down' }),
                },
            );
        },
        [mapId, createNotes, selectOnly],
    );

    const copy = useCallback(
        (ids: number[]) => {
            const list = copyable(ids);
            if (list.length === 0) return;
            copyNotesToClipboard(list);
            pushToast({ title: `Copied ${plural(list.length)}`, detail: `Paste with ${shortcut('V')} or right-click the canvas, on this map or another`, tone: 'info', key: 'note-clipboard' });
        },
        [copyable],
    );

    const duplicate = useCallback(
        (ids: number[]) => {
            const list = copyable(ids);
            if (list.length === 0) return;
            const at = { x: Math.min(...list.map((n) => n.x)) + DUPLICATE_OFFSET, y: Math.min(...list.map((n) => n.y)) + DUPLICATE_OFFSET };
            create(toClipboardNotes(list), at, 'duplicate'); // leaves the clipboard alone
        },
        [copyable, create],
    );

    /** Paste the clipboard with its top-left corner at this flow position. */
    const pasteAt = useCallback(
        (at: XY) => {
            const clip = getMapClipboard();
            if (clip?.kind === 'notes') create(clip.items, at, 'paste');
        },
        [create],
    );

    const remove = useCallback(
        (ids: number[]) => {
            if (mapId === null) return;
            ids.forEach((id) => deleteMapNote.mutate({ mapId, noteId: id }));
        },
        [mapId, deleteMapNote],
    );

    /** Where a keyboard paste goes: under the pointer if it's over the canvas, else its centre. */
    const keyboardPastePoint = useCallback((): XY | null => {
        const box = canvasEl?.getBoundingClientRect();
        if (!box) return null;
        const p = pointer.current;
        const inside = p !== null && p.x >= box.left && p.x <= box.right && p.y >= box.top && p.y <= box.bottom;
        return screenToFlowPosition(inside ? p : { x: box.left + box.width / 2, y: box.top + box.height / 2 });
    }, [canvasEl, screenToFlowPosition]);

    // Keyboard shortcuts. Read through a ref so the listener is attached once, not per render.
    const onKey = useRef<(e: KeyboardEvent) => void>(() => {});
    onKey.current = (e: KeyboardEvent) => {
        if (!enabled || mapId === null || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return;
        // Only when the map has focus: never while typing (a note being edited included), and not
        // while a dialog or menu elsewhere on the page holds focus.
        if (isTyping(e.target)) return;
        if (!(e.target === document.body || (e.target instanceof globalThis.Node && canvasEl?.contains(e.target)))) return;
        const key = e.key.toLowerCase();
        if (key === 'c') {
            if (window.getSelection()?.toString()) return; // copying page text - leave it to the browser
            const ids = selectedNoteIds();
            if (ids.length === 0) return;
            e.preventDefault();
            copy(ids);
        } else if (key === 'v') {
            if (getMapClipboard() === null) return;
            const at = keyboardPastePoint();
            if (at === null) return;
            e.preventDefault();
            pasteAt(at);
        } else if (key === 'd') {
            const ids = selectedNoteIds();
            if (ids.length === 0) return;
            e.preventDefault(); // the browser's "bookmark this page"
            duplicate(ids);
        }
    };
    useEffect(() => {
        const handler = (e: KeyboardEvent) => onKey.current(e);
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, []);

    return {
        clipboard,
        copy,
        duplicate,
        pasteAt,
        remove,
        /** Attach to the canvas wrapper's onPointerMove. */
        trackPointer: useCallback((e: { clientX: number; clientY: number }) => {
            pointer.current = { x: e.clientX, y: e.clientY };
        }, []),
        /**
         * For the node rebuild: should this newly-appeared node start selected? A pure read (a
         * state updater may run twice); stale ids are harmless, as note ids are never reused.
         */
        isPendingSelect: useCallback((nodeId: string) => pendingSelect.current.has(nodeId), []),
    };
}
