import { useEffect, useState } from 'react';
import { X, ShareNetwork, Copy, Check, Trash, Plus, LinkSimple } from '@phosphor-icons/react';
import { useMapShares, useCreateMapShare, useUpdateMapShare, useDeleteMapShare, type MapShareLink } from '../api/shares';
import { pushToast } from '../../../lib/toast';

/**
 * Manage a map's public wallboard links (GitHub #15): mint an unguessable no-login link, copy it,
 * turn it on/off, or revoke it. Admin-only (the caller only renders it for admins; the API also
 * enforces it). Anyone with a live link can view this map read-only, so it's framed as such.
 */
export function ShareWallboardDialog({ mapId, mapName, onClose }: { mapId: number; mapName: string; onClose: () => void }) {
    const { data: shares, isLoading } = useMapShares(mapId);
    const create = useCreateMapShare();
    const update = useUpdateMapShare();
    const del = useDeleteMapShare();
    const [copiedId, setCopiedId] = useState<number | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    async function copy(share: MapShareLink) {
        try {
            await navigator.clipboard.writeText(share.url);
            setCopiedId(share.id);
            setTimeout(() => setCopiedId((c) => (c === share.id ? null : c)), 1800);
        } catch {
            pushToast({ title: 'Could not copy', detail: share.url, tone: 'down' });
        }
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-lg rounded-2xl bg-[#0d0d11] p-5 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-400/20">
                            <ShareNetwork weight="light" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className="text-base font-bold tracking-tight text-white">Share wallboard</h2>
                            <p className="text-xs text-white/45">Public read-only view of "{mapName}"</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-white/40 hover:bg-white/5 hover:text-white/80">
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                </div>

                <p className="mb-3 rounded-lg bg-amber-500/5 px-3 py-2 text-[11px] leading-relaxed text-amber-200/70 ring-1 ring-amber-400/10">
                    Anyone with a live link can view this map's live status without logging in. It never allows any
                    changes and never shows management addresses. Turn a link off or remove it to cut access.
                </p>

                <div className="max-h-64 space-y-1.5 overflow-y-auto">
                    {isLoading && <p className="px-1 py-2 text-xs text-white/40">Loading links...</p>}
                    {!isLoading && (shares?.length ?? 0) === 0 && (
                        <p className="px-1 py-3 text-center text-xs text-white/40">No share links yet. Create one below.</p>
                    )}
                    {shares?.map((s) => (
                        <div key={s.id} className="flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/5">
                            <LinkSimple weight="bold" className={`h-3.5 w-3.5 shrink-0 ${s.enabled ? 'text-emerald-300' : 'text-white/25'}`} />
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-mono text-[11px] text-white/70">{s.url}</p>
                                <p className="text-[10px] text-white/35">
                                    {s.enabled ? 'Active' : 'Disabled'}
                                    {s.last_viewed_at ? ` - last viewed ${new Date(s.last_viewed_at).toLocaleDateString()}` : ' - not viewed yet'}
                                </p>
                            </div>
                            <button
                                onClick={() => copy(s)}
                                title="Copy link"
                                className="rounded-lg p-1.5 text-white/45 transition-colors hover:bg-white/5 hover:text-white/85"
                            >
                                {copiedId === s.id ? <Check weight="bold" className="h-3.5 w-3.5 text-emerald-300" /> : <Copy weight="bold" className="h-3.5 w-3.5" />}
                            </button>
                            <button
                                onClick={() => update.mutate({ mapId, id: s.id, enabled: !s.enabled })}
                                title={s.enabled ? 'Turn off' : 'Turn on'}
                                className={`rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 transition-colors ${
                                    s.enabled ? 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/20' : 'bg-white/5 text-white/45 ring-white/10'
                                }`}
                            >
                                {s.enabled ? 'On' : 'Off'}
                            </button>
                            <button
                                onClick={() => del.mutate({ mapId, id: s.id })}
                                title="Remove link"
                                className="rounded-lg p-1.5 text-white/40 transition-colors hover:bg-rose-500/10 hover:text-rose-300"
                            >
                                <Trash weight="bold" className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    ))}
                </div>

                <button
                    disabled={create.isPending}
                    onClick={() => create.mutate({ mapId })}
                    className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-500/15 px-3 py-2 text-sm font-medium text-emerald-200 ring-1 ring-emerald-400/25 transition-colors hover:bg-emerald-500/25 disabled:opacity-50"
                >
                    <Plus weight="bold" className="h-4 w-4" /> Create a link
                </button>
            </div>
        </div>
    );
}
