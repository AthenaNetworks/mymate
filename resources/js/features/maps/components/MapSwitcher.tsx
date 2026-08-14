import { useEffect, useRef, useState } from 'react';
import { CaretDown, DownloadSimple, GlobeHemisphereWest, MapTrifold, PencilSimple, Plus, ShareNetwork, Timer, Trash, UploadSimple } from '@phosphor-icons/react';
import { useMaps, useSaveMap, useDeleteMap, useExportMap, useImportMap } from '../api/maps';
import { useIsAdmin } from '../../auth/api/auth';
import { useActiveMapId, setActiveMap } from '../../../lib/shellStore';
import { pushToast } from '../../../lib/toast';
import { PromptDialog, ConfirmDialog } from '../../../components/Dialog';
import { ShareWallboardDialog } from './ShareWallboardDialog';
import type { NetworkMap } from '../../../types';

type MapDialog = { mode: 'create' } | { mode: 'rename'; id: number; current: string } | { mode: 'delete'; id: number; name: string };

/** Which map to land on: the flagged default if it has devices, else the first
 *  populated map (by the API\'s position/name order), else fall back to the default
 *  or the first map (everything\'s empty - nothing better to show). */
export function pickDefaultMap(maps: NetworkMap[]): NetworkMap {
    const flagged = maps.find((m) => m.is_default);
    if (flagged && flagged.device_count > 0) return flagged;
    return maps.find((m) => m.device_count > 0) ?? flagged ?? maps[0];
}

/** Switch / create / rename / delete maps. Drives the active map id
 *  in the shell store, which MapCanvas renders. Create/rename/delete use the app\'s
 *  styled dialogs, never the browser\'s native prompt/confirm. */
export function MapSwitcher() {
    const isAdmin = useIsAdmin();
    const { data: maps } = useMaps();
    const activeId = useActiveMapId();
    const saveMap = useSaveMap();
    const delMap = useDeleteMap();
    const exportMap = useExportMap();
    const importMap = useImportMap();
    const fileRef = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [dialog, setDialog] = useState<MapDialog | null>(null);
    const [sharing, setSharing] = useState(false); // the "Share wallboard" dialog for the active map

    // Default the active map once maps load. Prefer one that actually has devices on
    // it, so a fresh/empty default map (e.g. the seed "Main" alongside imported maps)
    // never strands you on a blank canvas - land on the topology you can see.
    useEffect(() => {
        // Default when no map is active, or when a persisted id no longer exists (deleted).
        if (maps && maps.length > 0 && (activeId === null || !maps.some((m) => m.id === activeId))) {
            setActiveMap(pickDefaultMap(maps).id);
        }
    }, [activeId, maps]);

    const active = maps?.find((m) => m.id === activeId) ?? null;

    function openDialog(d: MapDialog) {
        setOpen(false);
        setDialog(d);
    }

    function doCreate(name: string) {
        saveMap.mutate(
            { name },
            {
                onSuccess: (m) => {
                    setActiveMap(m.id);
                    setDialog(null);
                    pushToast({ title: `Map "${m.name}" created`, tone: 'info' });
                },
                onError: () => pushToast({ title: 'Couldn\'t create the map', tone: 'down' }),
            },
        );
    }

    function doRename(id: number, name: string) {
        saveMap.mutate(
            { id, name },
            {
                onSuccess: () => setDialog(null),
                onError: () => pushToast({ title: 'Couldn\'t rename the map', tone: 'down' }),
            },
        );
    }

    function doDelete(id: number) {
        delMap.mutate(id, {
            onSuccess: () => {
                if (activeId === id) {
                    const remaining = maps?.filter((m) => m.id !== id) ?? [];
                    setActiveMap(remaining.length > 0 ? pickDefaultMap(remaining).id : null);
                }
                setDialog(null);
            },
            onError: () => pushToast({ title: 'Couldn\'t delete the map', tone: 'down' }),
        });
    }

    return (
        <>
            <div className="relative">
                <button
                    onClick={() => setOpen((o) => !o)}
                    className="flex items-center gap-2 rounded-full bg-surface/80 px-3 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 backdrop-blur-xl transition-colors hover:text-white"
                >
                    <MapTrifold weight="light" className="h-4 w-4 text-emerald-300" />
                    {active?.name ?? 'Map'}
                    <CaretDown weight="bold" className="h-3 w-3 text-white/40" />
                </button>

                {open && (
                    <>
                        <button aria-hidden tabIndex={-1} className="fixed inset-0 z-10 cursor-default" onClick={() => setOpen(false)} />
                        <div className="absolute left-0 z-20 mt-1.5 flex max-h-[70vh] w-64 flex-col rounded-xl bg-surface p-1 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                            <div className="min-h-0 flex-1 overflow-y-auto">
                            {maps?.map((m) => (
                                <div key={m.id} className={`group flex items-center gap-0.5 rounded-lg ${m.id === activeId ? 'bg-emerald-500/10' : ''}`}>
                                    <button
                                        onClick={() => {
                                            setActiveMap(m.id);
                                            setOpen(false);
                                        }}
                                        className="min-w-0 flex-1 truncate px-2.5 py-1.5 text-left text-sm text-white/85"
                                    >
                                        {m.parent_map_id ? '- ' : ''}
                                        {m.name}
                                        <span className="text-white/30"> - {m.device_count}</span>
                                    </button>
                                    {isAdmin && (
                                        <>
                                            <button onClick={() => openDialog({ mode: 'rename', id: m.id, current: m.name })} title="Rename" className="rounded p-1 text-white/30 hover:text-white/70">
                                                <PencilSimple weight="bold" className="h-3 w-3" />
                                            </button>
                                            {!m.is_default && (
                                                <button onClick={() => openDialog({ mode: 'delete', id: m.id, name: m.name })} title="Delete" className="rounded p-1 text-white/30 hover:text-rose-300">
                                                    <Trash weight="bold" className="h-3 w-3" />
                                                </button>
                                            )}
                                        </>
                                    )}
                                </div>
                            ))}
                            </div>
                            {isAdmin && (
                                <div className="mt-1 shrink-0 border-t border-white/5 pt-1">
                                    <button onClick={() => openDialog({ mode: 'create' })} className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-emerald-300 transition-colors hover:bg-white/5">
                                        <Plus weight="bold" className="h-3.5 w-3.5" /> New map
                                    </button>
                                    <button
                                        disabled={!active || saveMap.isPending}
                                        onClick={() => active && saveMap.mutate({ id: active.id, name: active.name, leaflet_enabled: !active.leaflet_enabled })}
                                        className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-white/70 transition-colors hover:bg-white/5 disabled:opacity-40"
                                    >
                                        <GlobeHemisphereWest weight="bold" className={`h-3.5 w-3.5 ${active?.leaflet_enabled ? 'text-emerald-300' : 'text-white/40'}`} />
                                        Geographic mode {active?.leaflet_enabled ? 'on' : 'off'}
                                    </button>
                                    {/* Per-map ping cadence (GitHub #32): faster for a critical map, slower to
                                        save resources elsewhere. Blank = the global interval. A device on
                                        several maps takes the fastest. */}
                                    <div className="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-white/70">
                                        <Timer weight="bold" className={`h-3.5 w-3.5 ${active?.ping_interval ? 'text-emerald-300' : 'text-white/40'}`} />
                                        <span className="flex-1">Ping interval</span>
                                        <select
                                            disabled={!active || saveMap.isPending}
                                            value={active?.ping_interval ?? ''}
                                            onChange={(e) => active && saveMap.mutate({
                                                id: active.id,
                                                name: active.name,
                                                ping_interval: e.target.value === '' ? null : Number(e.target.value),
                                            })}
                                            title="Up/down ping cadence for devices on this map"
                                            className="rounded-md bg-white/5 px-2 py-1 text-xs text-white/80 ring-1 ring-white/10 transition-colors hover:bg-white/10 focus:outline-none focus:ring-emerald-400/40 disabled:opacity-40"
                                        >
                                            <option value="">Global</option>
                                            <option value="5">5s</option>
                                            <option value="10">10s</option>
                                            <option value="30">30s</option>
                                            <option value="60">60s</option>
                                            <option value="120">120s</option>
                                        </select>
                                    </div>
                                    <button
                                        disabled={!active}
                                        onClick={() => { setSharing(true); setOpen(false); }}
                                        className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-white/70 transition-colors hover:bg-white/5 disabled:opacity-40"
                                    >
                                        <ShareNetwork weight="bold" className="h-3.5 w-3.5 text-emerald-300" /> Share wallboard
                                    </button>
                                    <button
                                        disabled={!active || exportMap.isPending}
                                        onClick={() => active && exportMap.mutate({ mapId: active.id, name: active.name })}
                                        className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-white/70 transition-colors hover:bg-white/5 disabled:opacity-40"
                                    >
                                        <DownloadSimple weight="bold" className="h-3.5 w-3.5" /> Export this map
                                    </button>
                                    <button
                                        disabled={importMap.isPending}
                                        onClick={() => fileRef.current?.click()}
                                        className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-white/70 transition-colors hover:bg-white/5 disabled:opacity-40"
                                    >
                                        <UploadSimple weight="bold" className="h-3.5 w-3.5" /> Import a map
                                    </button>
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        accept="application/json,.json"
                                        className="hidden"
                                        onChange={async (e) => {
                                            const file = e.target.files?.[0];
                                            e.target.value = ''; // allow re-picking the same file
                                            if (!file) return;
                                            try {
                                                const payload = JSON.parse(await file.text());
                                                const map = await importMap.mutateAsync(payload);
                                                setActiveMap(map.id);
                                                setOpen(false);
                                                pushToast({ title: `Imported "${map.name}"`, tone: 'info' });
                                            } catch {
                                                pushToast({ title: "Couldn't import that file", detail: 'Expected a map export JSON.', tone: 'down' });
                                            }
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>

            {sharing && active && (
                <ShareWallboardDialog mapId={active.id} mapName={active.name} onClose={() => setSharing(false)} />
            )}

            {dialog?.mode === 'create' && (
                <PromptDialog
                    title="New map"
                    label="Map name"
                    icon={<MapTrifold weight="light" className="h-5 w-5" />}
                    placeholder="e.g. North Region"
                    confirmLabel="Create map"
                    busy={saveMap.isPending}
                    onSubmit={doCreate}
                    onClose={() => setDialog(null)}
                />
            )}

            {dialog?.mode === 'rename' && (
                <PromptDialog
                    title="Rename map"
                    label="Map name"
                    icon={<MapTrifold weight="light" className="h-5 w-5" />}
                    initial={dialog.current}
                    confirmLabel="Save name"
                    busy={saveMap.isPending}
                    onSubmit={(name) => doRename(dialog.id, name)}
                    onClose={() => setDialog(null)}
                />
            )}

            {dialog?.mode === 'delete' && (
                <ConfirmDialog
                    title="Delete map"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete map <span className="font-semibold text-white/85">"{dialog.name}"</span>? Devices stay - only this map's layout is removed.
                        </>
                    }
                    confirmLabel="Delete map"
                    tone="danger"
                    busy={delMap.isPending}
                    onConfirm={() => doDelete(dialog.id)}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}
