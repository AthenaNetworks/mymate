import { useState } from 'react';
import { LinkSimple, X } from '@phosphor-icons/react';
import { useUpdateMapLink } from '../../maps/api/maps';
import { useIsAdmin } from '../../auth/api/auth';
import { MediaTypePicker } from './MediaTypePicker';
import type { LinkMediaType, MapLink } from '../../../types';

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid placeholder:text-white/30 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

/** Edit a manual overview link's medium + label (GitHub #9). */
export function MapLinkEditor({ mapId, link, onClose }: { mapId: number; link: MapLink; onClose: () => void }) {
    const isAdmin = useIsAdmin();
    const update = useUpdateMapLink();
    const [media, setMedia] = useState<LinkMediaType | null>(link.media_type);
    const [label, setLabel] = useState(link.label ?? '');

    if (!isAdmin) return null;

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-sm rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="space-y-4 rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-400/20">
                                <LinkSimple weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Overview link</h2>
                                <p className="text-xs text-white/40">Style this link by its medium</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <MediaTypePicker value={media} onChange={setMedia} />

                    <label className="block space-y-1.5">
                        <span className="px-1 text-[11px] font-medium text-white/55">Label (optional)</span>
                        <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. 10G DWDM" maxLength={80} className={field} />
                    </label>

                    <div className="flex items-center justify-end gap-2.5">
                        <button onClick={onClose} className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors hover:text-white/90">
                            Cancel
                        </button>
                        <button
                            onClick={() =>
                                update.mutate(
                                    { mapId, mapLinkId: link.id, media_type: media, label: label.trim() === '' ? null : label.trim() },
                                    { onSuccess: onClose },
                                )
                            }
                            disabled={update.isPending}
                            className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                        >
                            {update.isPending ? 'Saving...' : 'Save'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
