import { useRef, useState } from 'react';
import { ImageSquare, Trash, UploadSimple } from '@phosphor-icons/react';
import { ModalShell } from '../../../components/Dialog';
import { imageAssetUrl } from '../../../lib/imageAssets';
import { pushToast } from '../../../lib/toast';
import { useIsAdmin } from '../../auth/api/auth';
import { useDeleteImageAsset, useImageAssets, useUploadImageAsset } from '../api/imageAssets';
import type { ImageAsset } from '../../../types';

const ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml,.png,.jpg,.jpeg,.webp,.svg';

/**
 * Pick an image from the library, or upload a new one (GitHub #53). Used for a device's icon and
 * for placing a picture on a map. An admin can also delete images from here - that takes them off
 * every map and puts devices using one back on their automatic icon, so it asks first.
 */
export function ImageLibraryDialog({ title, onPick, onClose }: { title: string; onPick: (asset: ImageAsset) => void; onClose: () => void }) {
    const isAdmin = useIsAdmin();
    const { data: assets, isLoading } = useImageAssets();
    const upload = useUploadImageAsset();
    const remove = useDeleteImageAsset();
    const fileRef = useRef<HTMLInputElement>(null);
    const [confirmId, setConfirmId] = useState<number | null>(null);

    function pick(file: File | undefined) {
        if (!file) return;
        upload.mutate(
            { file },
            {
                onSuccess: (asset) => onPick(asset),
                onError: (e: unknown) => {
                    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
                    pushToast({ title: "Couldn't upload that image", detail: msg, tone: 'down' });
                },
            },
        );
    }

    return (
        <ModalShell icon={<ImageSquare weight="light" className="h-5 w-5" />} title={title} onClose={onClose}>
            {isLoading ? (
                <p className="text-sm text-white/50">Loading...</p>
            ) : assets?.length ? (
                <div className="grid grid-cols-3 gap-2">
                    {assets.map((a) => (
                        <div key={a.id} className="group relative">
                            <button
                                type="button"
                                onClick={() => onPick(a)}
                                title={a.name}
                                className="grid aspect-square w-full place-items-center overflow-hidden rounded-xl bg-white/[0.04] p-2 ring-1 ring-white/10 transition hover:ring-emerald-400/50"
                            >
                                <img src={imageAssetUrl(a.id)} alt={a.name} className="max-h-full max-w-full object-contain" />
                            </button>
                            <span className="mt-1 block truncate text-center text-[10px] text-white/45">{a.name}</span>
                            {isAdmin && (
                                confirmId === a.id ? (
                                    <button
                                        type="button"
                                        disabled={remove.isPending}
                                        onClick={() => remove.mutate(a.id, { onSettled: () => setConfirmId(null) })}
                                        className="absolute inset-x-1 top-1 rounded-lg bg-rose-600/90 px-1 py-1 text-[10px] font-semibold text-white"
                                        title="Removes it from every map, and devices using it go back to their automatic icon"
                                    >
                                        Delete everywhere?
                                    </button>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => setConfirmId(a.id)}
                                        title="Delete from the library"
                                        className="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-zinc-900/90 text-white/60 opacity-100 ring-1 ring-white/15 hover:text-rose-300 lg:opacity-0 lg:group-hover:opacity-100"
                                    >
                                        <Trash weight="bold" className="h-3 w-3" />
                                    </button>
                                )
                            )}
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-white/50">No images yet.{isAdmin ? ' Upload one to get started.' : ''}</p>
            )}

            {isAdmin && (
                <div className="mt-4 flex items-center justify-between gap-2">
                    <span className="text-[11px] text-white/40">PNG, JPEG, WebP or SVG</span>
                    <button
                        type="button"
                        disabled={upload.isPending}
                        onClick={() => fileRef.current?.click()}
                        className="flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-3 py-1.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/25 hover:bg-emerald-500/25 disabled:opacity-40"
                    >
                        <UploadSimple weight="bold" className="h-3.5 w-3.5" /> {upload.isPending ? 'Uploading...' : 'Upload image'}
                    </button>
                    <input
                        ref={fileRef}
                        type="file"
                        accept={ACCEPT}
                        className="hidden"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            pick(file);
                        }}
                    />
                </div>
            )}
        </ModalShell>
    );
}
