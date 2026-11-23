import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export type CanvasMenuItem =
    | {
          key: string;
          label: string;
          icon: ReactNode;
          onSelect: () => void;
          shortcut?: string; // shown on the right, e.g. "Ctrl+C"
          disabled?: boolean;
          title?: string;
          danger?: boolean;
      }
    | 'divider';

const MARGIN = 8; // keep the menu clear of the viewport edges

/**
 * A small right-click menu at the pointer, used for map notes and the empty canvas. Same look
 * and behaviour as the device menu (MapNodeMenu): portalled to <body>, clamped into the viewport,
 * closed by Escape or any click outside, and closed before an item's action runs.
 */
export function CanvasMenu({ x, y, heading, items, onClose }: { x: number; y: number; heading?: string; items: CanvasMenuItem[]; onClose: () => void }) {
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState({ x, y });

    useLayoutEffect(() => {
        const box = ref.current?.getBoundingClientRect();
        if (!box) return;
        setPos({
            x: Math.max(MARGIN, Math.min(x, window.innerWidth - box.width - MARGIN)),
            y: Math.max(MARGIN, Math.min(y, window.innerHeight - box.height - MARGIN)),
        });
    }, [x, y]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const base =
        'flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors duration-200 ease-fluid disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent';

    return createPortal(
        <>
            <div className="fixed inset-0 z-40" onClick={onClose} onContextMenu={(e) => { e.preventDefault(); onClose(); }} />
            <div
                ref={ref}
                role="menu"
                style={{ left: pos.x, top: pos.y }}
                className="animate-rise fixed z-50 w-52 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl"
            >
                {heading && <p className="truncate px-2.5 pb-1.5 pt-1 text-xs font-semibold text-white/90">{heading}</p>}
                {items.map((item, i) =>
                    item === 'divider' ? (
                        <div key={`divider-${i}`} className="my-1 h-px bg-white/10" />
                    ) : (
                        <button
                            key={item.key}
                            type="button"
                            role="menuitem"
                            disabled={item.disabled}
                            title={item.title}
                            onClick={() => {
                                onClose();
                                item.onSelect();
                            }}
                            className={`${base} ${item.danger ? 'text-rose-300/90 hover:bg-rose-500/10' : 'text-white/75 hover:bg-white/10'}`}
                        >
                            <span className="grid h-4 w-4 shrink-0 place-items-center">{item.icon}</span>
                            <span className="flex-1">{item.label}</span>
                            {item.shortcut && <span className="text-[10px] text-white/35">{item.shortcut}</span>}
                        </button>
                    ),
                )}
            </div>
        </>,
        document.body,
    );
}
