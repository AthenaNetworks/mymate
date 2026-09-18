import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, MagnifyingGlass, TreeStructure, X } from '@phosphor-icons/react';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useIsAdmin } from '../../auth/api/auth';
import { StatusDot } from '../../../components/StatusDot';
import type { Device } from '../../../types';

/**
 * Pick the upstream device a device hangs off (GitHub #45) - opened from the map's node menu
 * and from the inspector's Parent cell, so re-homing gear never means leaving the map.
 *
 * Parent is the hierarchy the rest of the app reads: dependency-aware alert suppression,
 * downstream-first upgrade ordering, geo coordinate inheritance and the tree/dependency
 * layouts. Candidates are the whole fleet, not just this map - an uplink often lives on
 * another map (or none at all).
 */

/** Every device below this one, so the picker can't offer a parent that would close a loop.
 *  Mirrors the server's NotADeviceDescendant rule; cycle-guarded, like every parent walk. */
function descendantIds(rootId: number, devices: Device[]): Set<number> {
    const childrenOf = new Map<number, number[]>();
    for (const d of devices) {
        if (d.parent_device_id === null) continue;
        const siblings = childrenOf.get(d.parent_device_id);
        if (siblings) siblings.push(d.id);
        else childrenOf.set(d.parent_device_id, [d.id]);
    }

    const out = new Set<number>();
    const queue = [rootId];
    while (queue.length > 0) {
        for (const childId of childrenOf.get(queue.pop() as number) ?? []) {
            if (out.has(childId)) continue; // a loop already in the data ends the walk here
            out.add(childId);
            queue.push(childId);
        }
    }
    return out;
}

export function SetParentDialog({ device, devices, onClose }: { device: Device; devices: Device[]; onClose: () => void }) {
    const isAdmin = useIsAdmin();
    const update = useUpdateDevice();
    const [q, setQ] = useState('');

    // Escape closes, regardless of focus - the backdrop click handles the rest.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const nameById = useMemo(() => new Map(devices.map((d) => [d.id, d.name])), [devices]);
    const excluded = useMemo(() => {
        const below = descendantIds(device.id, devices);
        below.add(device.id); // nor itself
        return below;
    }, [device.id, devices]);

    const needle = q.trim().toLowerCase();
    const candidates = useMemo(
        () =>
            devices
                .filter((d) => !excluded.has(d.id))
                .filter((d) => needle === '' || d.name.toLowerCase().includes(needle) || d.mgmt_ip.includes(needle))
                .sort((a, b) => a.name.localeCompare(b.name)),
        [devices, excluded, needle],
    );

    if (!isAdmin) return null;

    // Surface the server's actual reason (e.g. the loop guard) rather than a generic failure.
    const serverError = (() => {
        const e = update.error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null;
        const data = e?.response?.data;
        if (!data) return null;
        return data.errors?.parent_device_id?.[0] ?? data.message ?? null;
    })();

    function choose(parentId: number | null) {
        if (parentId === device.parent_device_id) return onClose();
        update.mutate({ id: device.id, parent_device_id: parentId }, { onSuccess: onClose });
    }

    const row = 'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left transition-colors duration-200 ease-fluid hover:bg-white/5 disabled:opacity-50';

    // Portal to <body>: the inspector pane is a transform/backdrop-filter ancestor, which would
    // otherwise trap this `fixed` overlay inside the narrow pane (as in GitHub #39).
    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <TreeStructure weight="light" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="truncate text-base font-bold tracking-tight text-white">Parent of {device.name}</h2>
                                <p className="truncate text-xs text-white/40">
                                    The upstream device it depends on - drives alert suppression, upgrade order and the tree layouts
                                </p>
                            </div>
                        </div>
                        <button onClick={onClose} className="ml-2 shrink-0 rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <div className="mb-2 flex items-center gap-2 rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/10">
                        <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search devices by name or IP"
                            className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                        />
                    </div>

                    {serverError && <p className="mb-2 px-1 text-xs text-rose-400/90">{serverError}</p>}

                    <ul className="max-h-72 space-y-1 overflow-auto">
                        <li>
                            <button type="button" disabled={update.isPending} onClick={() => choose(null)} className={`${row} ring-1 ring-white/10`}>
                                <span className="h-2 w-2 shrink-0 rounded-full bg-white/15" />
                                <span className="min-w-0 flex-1 truncate text-sm text-white/90">No parent</span>
                                {device.parent_device_id === null && <Check weight="bold" className="h-4 w-4 shrink-0 text-emerald-300" />}
                            </button>
                        </li>
                        {candidates.length === 0 ? (
                            <li className="px-3 py-6 text-center text-xs text-white/35">
                                {needle === '' ? 'No other devices to parent this one to.' : `No devices match "${q}".`}
                            </li>
                        ) : (
                            candidates.map((d) => (
                                <li key={d.id}>
                                    <button type="button" disabled={update.isPending} onClick={() => choose(d.id)} className={row}>
                                        <StatusDot status={d.status} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm text-white/90">{d.name}</span>
                                            <span className="block truncate text-[11px] text-white/35">
                                                {d.mgmt_ip}
                                                {d.parent_device_id !== null && ` - under ${nameById.get(d.parent_device_id) ?? 'another device'}`}
                                            </span>
                                        </span>
                                        {device.parent_device_id === d.id && <Check weight="bold" className="h-4 w-4 shrink-0 text-emerald-300" />}
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>

                    <p className="mt-3 px-1 text-[11px] text-white/30">
                        {device.name} and anything below it are left out - a device can't hang off its own downstream gear.
                    </p>
                </div>
            </div>
        </div>,
        document.body,
    );
}
