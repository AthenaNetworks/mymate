import { useState } from 'react';
import { MapTrifold, MagnifyingGlass, X } from '@phosphor-icons/react';
import { useMaps, useAddChildMap } from '../../maps/api/maps';
import { useIsAdmin } from '../../auth/api/auth';

/**
 * Place an existing map as a node on the current canvas (GitHub #9) - the building block of a
 * top-level overview map. Excludes the canvas itself and any map already placed here.
 */
export function AddChildMapDialog({ mapId, onClose }: { mapId: number; onClose: () => void }) {
    const isAdmin = useIsAdmin();
    const { data: maps } = useMaps();
    const add = useAddChildMap();
    const [q, setQ] = useState('');

    if (!isAdmin) return null;

    const needle = q.trim().toLowerCase();
    // Candidates: any map that isn't this canvas and isn't already a child node of it. (The
    // backend also rejects a cycle.)
    const candidates = (maps ?? []).filter(
        (m) => m.id !== mapId && m.parent_map_id !== mapId && (needle === '' || m.name.toLowerCase().includes(needle)),
    );

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-indigo-500/15 text-indigo-300 ring-1 ring-indigo-400/20">
                                <MapTrifold weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Add a map</h2>
                                <p className="text-xs text-white/40">Place a map as a node on this overview, then link them</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <div className="mb-2 flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/10">
                        <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search maps"
                            className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                        />
                    </div>

                    <ul className="max-h-72 space-y-1 overflow-auto">
                        {candidates.length === 0 ? (
                            <li className="px-3 py-6 text-center text-xs text-white/35">No maps to add.</li>
                        ) : (
                            candidates.map((m) => (
                                <li key={m.id}>
                                    <button
                                        type="button"
                                        disabled={add.isPending}
                                        onClick={() => add.mutate({ mapId, childMapId: m.id }, { onSuccess: onClose })}
                                        className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left ring-1 ring-white/10 transition-colors hover:bg-white/5 disabled:opacity-50"
                                    >
                                        <MapTrifold weight="duotone" className="h-4 w-4 shrink-0 text-indigo-300" />
                                        <span className="min-w-0 flex-1 truncate text-sm text-white/90">{m.name}</span>
                                        <span className="shrink-0 text-[11px] text-white/40">{m.device_count} devices</span>
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            </div>
        </div>
    );
}
