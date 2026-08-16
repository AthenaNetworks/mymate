import { useEffect, useRef, useState } from 'react';
import { type NodeProps } from '@xyflow/react';
import { ArrowCounterClockwise, X } from '@phosphor-icons/react';
import type { MapNoteSize } from '../../../types';

export type MapNoteStylePatch = { color?: string | null; background?: string | null; size?: MapNoteSize | null };

export type MapNoteNodeData = {
    noteId: number;
    text: string;
    color: string | null;
    background: string | null;
    size: MapNoteSize | null;
    editable: boolean; // admin
    onSave?: (text: string) => void;
    onSaveStyle?: (patch: MapNoteStylePatch) => void;
    onRemove?: () => void;
};

// Size preset -> text size utility. Default (null) is md.
const SIZE_CLASS: Record<MapNoteSize, string> = { sm: 'text-xs', md: 'text-sm', lg: 'text-lg' };
// Fallbacks shown in the colour pickers when a note is on the theme default (which has no fixed hex).
const PICK_TEXT = '#e8e6dc';
const PICK_BG = '#15151b';

/**
 * A free-text note / label on the map (GitHub #11). Double-click to edit (admin); blur or Enter
 * saves, Escape cancels. When selected, an admin gets a small toolbar to set the text colour,
 * background and size. Colours default to theme-aware tokens so an unstyled note reads on both the
 * light and dark themes (the old hardcoded white vanished on light).
 */
export function MapNoteNode({ data, selected }: NodeProps) {
    const d = data as unknown as MapNoteNodeData;
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(d.text);
    const ref = useRef<HTMLTextAreaElement>(null);

    useEffect(() => setDraft(d.text), [d.text]);
    useEffect(() => {
        if (editing) ref.current?.focus();
    }, [editing]);

    const commit = () => {
        setEditing(false);
        const next = draft.trim();
        if (next !== '' && next !== d.text) d.onSave?.(next);
        else setDraft(d.text);
    };

    const size = d.size ?? 'md';
    const showTools = d.editable && selected && !editing && d.onSaveStyle;

    return (
        <div
            onDoubleClick={() => d.editable && setEditing(true)}
            className={`group relative max-w-[16rem] rounded-lg px-2.5 py-1.5 ring-1 transition-all duration-200 ${SIZE_CLASS[size]} ${
                selected ? 'ring-emerald-400/70' : 'ring-white/10'
            }`}
            style={{ background: d.background ?? 'var(--color-surface-2)', color: d.color ?? 'var(--color-white)' }}
            title={d.editable ? 'Double-click to edit' : undefined}
        >
            {/* Style toolbar (admin, when selected): text colour, background, size, reset. */}
            {showTools && (
                <div className="nodrag absolute -top-9 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-lg bg-surface/95 px-2 py-1 shadow-[0_8px_24px_-8px_rgba(0,0,0,0.8)] ring-1 ring-white/10">
                    <label className="relative h-4 w-4 cursor-pointer" title="Text colour">
                        <span className="block h-4 w-4 rounded-full ring-1 ring-white/25" style={{ background: d.color ?? PICK_TEXT }} />
                        <input type="color" value={d.color ?? PICK_TEXT} onChange={(e) => d.onSaveStyle?.({ color: e.target.value })} className="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Text colour" />
                    </label>
                    <label className="relative h-4 w-4 cursor-pointer" title="Background colour">
                        <span className="block h-4 w-4 rounded ring-1 ring-white/25" style={{ background: d.background ?? PICK_BG }} />
                        <input type="color" value={d.background ?? PICK_BG} onChange={(e) => d.onSaveStyle?.({ background: e.target.value })} className="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Background colour" />
                    </label>
                    <span className="mx-0.5 h-4 w-px bg-white/10" />
                    {(['sm', 'md', 'lg'] as MapNoteSize[]).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => d.onSaveStyle?.({ size: s })}
                            className={`rounded px-1 text-[11px] font-semibold ${size === s ? 'bg-white/15 text-white' : 'text-white/45 hover:text-white/80'}`}
                            title={{ sm: 'Small', md: 'Medium', lg: 'Large' }[s]}
                        >
                            {s.toUpperCase()}
                        </button>
                    ))}
                    <span className="mx-0.5 h-4 w-px bg-white/10" />
                    <button type="button" onClick={() => d.onSaveStyle?.({ color: null, background: null, size: null })} title="Reset to theme default" className="rounded p-0.5 text-white/45 hover:text-white/80">
                        <ArrowCounterClockwise weight="bold" className="h-3 w-3" />
                    </button>
                </div>
            )}

            {editing ? (
                <textarea
                    ref={ref}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={commit}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); }
                        if (e.key === 'Escape') { setDraft(d.text); setEditing(false); }
                    }}
                    rows={Math.min(6, Math.max(1, draft.split('\n').length))}
                    maxLength={500}
                    className="nodrag w-44 resize-none rounded bg-black/40 px-1 py-0.5 text-sm text-white outline-none ring-1 ring-emerald-400/40"
                />
            ) : (
                <span className="whitespace-pre-wrap break-words font-medium">{d.text || '(empty note)'}</span>
            )}

            {d.editable && d.onRemove && !editing && (
                <button
                    type="button"
                    title="Delete note"
                    onClick={(e) => { e.stopPropagation(); d.onRemove?.(); }}
                    className="nodrag absolute -right-1.5 -top-1.5 z-10 grid h-4 w-4 place-items-center rounded-full bg-rose-500/80 text-white opacity-0 ring-1 ring-rose-300/40 transition-opacity duration-200 group-hover:opacity-100 hover:!bg-rose-500"
                >
                    <X weight="bold" className="h-2.5 w-2.5" />
                </button>
            )}
        </div>
    );
}
