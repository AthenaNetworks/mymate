import { useCallback, useEffect, useRef, useState } from 'react';
import { Panel, useReactFlow, useStore } from '@xyflow/react';
import { Crosshair, ImageSquare, Trash, UploadSimple, X } from '@phosphor-icons/react';
import { MapBackgroundLayer } from './MapBackgroundLayer';
import { ConfirmDialog } from '../../../components/Dialog';
import { useIsAdmin } from '../../auth/api/auth';
import { pushToast } from '../../../lib/toast';
import {
    mapBackgroundUrl,
    setBackgroundEditorOpen,
    useBackgroundEditorOpen,
    useDeleteMapBackground,
    useMapBackground,
    useUpdateMapBackground,
    useUploadMapBackground,
    type BackgroundPlacement,
} from '../../maps/api/background';
import type { MapBackground as Bg } from '../../../types';

const ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml,.png,.jpg,.jpeg,.webp,.svg';

/**
 * The logged-in canvas's background image (GitHub #37): the image layer, plus the admin's
 * placement panel when it's been opened from the map menu. Drop it inside <ReactFlow> - it owns its
 * own query and state, so the canvas only has to pass the map id.
 *
 * While the panel is open, edits drive a local draft so the image moves as you drag a slider,
 * and are saved (debounced) behind it. Nothing here goes near the node or edge state.
 */
export function MapBackground({ mapId }: { mapId: number | null }) {
    const { data: bg } = useMapBackground(mapId);
    const editing = useBackgroundEditorOpen() && mapId !== null;
    const isAdmin = useIsAdmin();
    const [draft, setDraft] = useState<Partial<BackgroundPlacement>>({});

    // A different map drops the draft - the cache has the truth. (Closing the panel keeps it: the
    // last change is still being saved, and dropping it early would flash the old placement.)
    useEffect(() => setDraft({}), [mapId]);
    // Switching maps closes the panel rather than carrying it over to a map you didn't open it on.
    useEffect(() => () => setBackgroundEditorOpen(false), [mapId]);

    const shown = bg ? { ...bg, ...draft } : null;

    return (
        <>
            {shown && mapId !== null && (
                <MapBackgroundLayer
                    src={mapBackgroundUrl(mapId, shown)}
                    width={shown.width}
                    height={shown.height}
                    x={shown.x}
                    y={shown.y}
                    scale={shown.scale}
                    opacity={shown.opacity}
                />
            )}
            {editing && isAdmin && mapId !== null && (
                <BackgroundEditor mapId={mapId} bg={shown} onDraft={(p) => setDraft((d) => ({ ...d, ...p }))} />
            )}
        </>
    );
}

function BackgroundEditor({ mapId, bg, onDraft }: { mapId: number; bg: Bg | null; onDraft: (p: Partial<BackgroundPlacement>) => void }) {
    const upload = useUploadMapBackground();
    const update = useUpdateMapBackground();
    const remove = useDeleteMapBackground();
    const fileRef = useRef<HTMLInputElement>(null);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const { getViewport } = useReactFlow();
    const paneW = useStore((s) => s.width);
    const paneH = useStore((s) => s.height);

    // Debounced save: sliders fire on every pixel, the server only needs where you let go.
    const pending = useRef<Partial<BackgroundPlacement>>({});
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const flush = useCallback(() => {
        if (timer.current) clearTimeout(timer.current);
        timer.current = null;
        const body = pending.current;
        pending.current = {};
        if (Object.keys(body).length === 0) return;
        update.mutate({ mapId, ...body }, {
            onError: () => pushToast({ title: "Couldn't save the background placement", tone: 'down' }),
        });
    }, [mapId, update]);
    const change = (p: Partial<BackgroundPlacement>) => {
        onDraft(p);
        pending.current = { ...pending.current, ...p };
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(flush, 400);
    };
    // Closing the panel (or leaving the map) saves whatever was still waiting.
    const flushRef = useRef(flush);
    flushRef.current = flush;
    useEffect(() => () => flushRef.current(), []);

    function pick(file: File | undefined) {
        if (!file) return;
        upload.mutate({ mapId, file }, {
            onSuccess: () => pushToast({ title: 'Background image updated', tone: 'up' }),
            onError: (e: unknown) => {
                const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
                pushToast({ title: "Couldn't use that image", detail: msg ?? 'Use a PNG, JPEG, WebP or SVG image.', tone: 'down' });
            },
        });
    }

    // Drop the image's centre on the middle of what you're looking at - handy when it's off-screen.
    function centreInView() {
        if (!bg) return;
        const vp = getViewport();
        const cx = (paneW / 2 - vp.x) / vp.zoom;
        const cy = (paneH / 2 - vp.y) / vp.zoom;
        change({ x: Math.round(cx - (bg.width * bg.scale) / 2), y: Math.round(cy - (bg.height * bg.scale) / 2) });
    }

    const num = 'w-20 rounded-md bg-white/5 px-2 py-1 font-mono text-xs tabular-nums text-white/80 ring-1 ring-white/10 focus:outline-none focus:ring-emerald-400/40';
    const btn = 'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 transition-colors disabled:opacity-40';

    return (
        <>
            <Panel position="bottom-center" className="!mb-4">
                <div className="w-[min(92vw,34rem)] rounded-2xl bg-surface/90 p-3 shadow-[0_20px_50px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2 text-sm font-semibold text-white/85">
                            <ImageSquare weight="light" className="h-4 w-4 text-emerald-300" /> Background image
                        </div>
                        <button onClick={() => setBackgroundEditorOpen(false)} title="Close" className="rounded-lg p-1 text-white/40 hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </div>
    
                    {!bg && (
                        <p className="mb-2 text-xs text-white/45">
                            Put a floor plan, site photo or rack diagram behind this map's devices. PNG, JPEG, WebP or SVG.
                        </p>
                    )}
    
                    {bg && (
                        <div className="mb-3 grid grid-cols-1 gap-2 text-xs text-white/60 sm:grid-cols-2">
                            <label className="flex items-center gap-2">
                                <span className="w-14">Position</span>
                                <input type="number" className={num} value={Math.round(bg.x)} onChange={(e) => e.target.value !== '' && change({ x: Number(e.target.value) })} title="X" />
                                <input type="number" className={num} value={Math.round(bg.y)} onChange={(e) => e.target.value !== '' && change({ y: Number(e.target.value) })} title="Y" />
                            </label>
                            <label className="flex items-center gap-2">
                                <span className="w-14">Scale</span>
                                {/* Log slider: 0.05x .. 20x, so small and large both get useful travel. */}
                                <input
                                    type="range" min={-3} max={3} step={0.01}
                                    value={Math.log(bg.scale)}
                                    onChange={(e) => change({ scale: Number(Math.exp(Number(e.target.value)).toFixed(3)) })}
                                    className="min-w-0 flex-1 accent-emerald-400"
                                />
                                <span className="w-12 text-right font-mono tabular-nums">{bg.scale.toFixed(2)}x</span>
                            </label>
                            <label className="flex items-center gap-2 sm:col-span-2">
                                <span className="w-14">Opacity</span>
                                <input
                                    type="range" min={0} max={1} step={0.01}
                                    value={bg.opacity}
                                    onChange={(e) => change({ opacity: Number(e.target.value) })}
                                    className="min-w-0 flex-1 accent-emerald-400"
                                />
                                <span className="w-12 text-right font-mono tabular-nums">{Math.round(bg.opacity * 100)}%</span>
                            </label>
                        </div>
                    )}
    
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            disabled={upload.isPending}
                            onClick={() => fileRef.current?.click()}
                            className={`${btn} bg-emerald-500/15 text-emerald-200 ring-emerald-400/25 hover:bg-emerald-500/25`}
                        >
                            <UploadSimple weight="bold" className="h-3.5 w-3.5" /> {upload.isPending ? 'Uploading...' : bg ? 'Replace image' : 'Upload image'}
                        </button>
                        {bg && (
                            <>
                                <button onClick={centreInView} className={`${btn} bg-white/5 text-white/70 ring-white/10 hover:bg-white/10`}>
                                    <Crosshair weight="bold" className="h-3.5 w-3.5" /> Centre in view
                                </button>
                                <button
                                    disabled={remove.isPending}
                                    onClick={() => setConfirmRemove(true)}
                                    className={`${btn} ml-auto bg-white/5 text-white/55 ring-white/10 hover:bg-rose-500/10 hover:text-rose-300`}
                                >
                                    <Trash weight="bold" className="h-3.5 w-3.5" /> Remove
                                </button>
                            </>
                        )}
                        <input
                            ref={fileRef}
                            type="file"
                            accept={ACCEPT}
                            className="hidden"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = ''; // allow re-picking the same file
                                pick(file);
                            }}
                        />
                    </div>
                </div>
            </Panel>

            {confirmRemove && (
                <ConfirmDialog
                    title="Remove the background image?"
                    tone="danger"
                    icon={<ImageSquare weight="light" className="h-5 w-5" />}
                    message="The image is deleted from the server. The devices on the map aren't touched."
                    confirmLabel="Remove image"
                    busy={remove.isPending}
                    onConfirm={() => {
                        pending.current = {}; // nothing left to save
                        remove.mutate({ mapId }, {
                            onSuccess: () => setConfirmRemove(false),
                            onError: () => { setConfirmRemove(false); pushToast({ title: "Couldn't remove the image", tone: 'down' }); },
                        });
                    }}
                    onClose={() => setConfirmRemove(false)}
                />
            )}
        </>
    );
}
