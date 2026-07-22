import { useEffect, useRef, useState } from 'react';
import { type NodeProps } from '@xyflow/react';
import { X } from '@phosphor-icons/react';

export type MapNoteNodeData = {
    noteId: number;
    text: string;
    color: string | null;
    editable: boolean; // admin
    onSave?: (text: string) => void;
    onRemove?: () => void;
};

/**
 * A free-text note / label on the map (GitHub #11). Double-click to edit (admin); blur or Enter
 * saves, Escape cancels. Not tied to any device or link.
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

    return (
        <div
            onDoubleClick={() => d.editable && setEditing(true)}
            className={`group relative max-w-[16rem] rounded-lg px-2.5 py-1.5 text-sm ring-1 transition-all duration-200 ${
                selected ? 'ring-emerald-400/70' : 'ring-white/10'
            }`}
            style={{ background: 'rgba(255,255,255,0.04)', color: d.color ?? 'rgba(255,255,255,0.85)' }}
            title={d.editable ? 'Double-click to edit' : undefined}
        >
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
