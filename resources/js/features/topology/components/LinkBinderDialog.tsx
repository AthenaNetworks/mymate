import { useState } from 'react';
import { CaretDown, LinkSimple, MagnifyingGlass, X } from '@phosphor-icons/react';
import { useDeviceInterfaces } from '../api/getDeviceInterfaces';
import { useDiscoverDevice } from '../api/discoverDevice';
import { useCreateLink } from '../api/createLink';
import { useIsAdmin } from '../../auth/api/auth';
import type { Device, NetworkInterface } from '../../../types';

export type PendingLink = { aDeviceId: number; bDeviceId: number };

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60 disabled:opacity-50';

function speedLabel(mbps: number | null): string {
    if (mbps === null || mbps === 0) return '';
    return mbps >= 1000 ? `${mbps / 1000}G` : `${mbps}M`;
}

function ifaceLabel(i: NetworkInterface): string {
    const s = speedLabel(i.speed_mbps);
    return s ? `${i.name} - ${s}` : i.name;
}

/** Interface picker for one link end - a custom listbox showing each interface\'s
 *  name (+ speed) with its description as sub-text. Reused by the create binder and
 *  the link-history Edit tab. */
export function EndPicker({
    device,
    value,
    onChange,
}: {
    device: Device | undefined;
    value: string;
    onChange: (v: string) => void;
}) {
    const ifs = useDeviceInterfaces(device?.id ?? null);
    const discover = useDiscoverDevice();
    const [open, setOpen] = useState(false);
    // Ping-only devices have no interfaces by design - the link binds to the device only.
    const pingOnly = device?.poll_method === 'none';
    const empty = !pingOnly && !ifs.isLoading && (ifs.data?.length ?? 0) === 0;
    const selected = ifs.data?.find((i) => String(i.id) === value);

    const buttonText = selected ? ifaceLabel(selected) : ifs.isLoading ? 'Loading interfaces...' : 'Select interface';

    return (
        <label className="block space-y-1.5">
            <span className="flex items-center gap-2 px-1 text-[11px] font-medium text-white/55">
                <span className="truncate font-semibold text-white/85">{device?.name ?? 'Device'}</span>
                <span className="truncate text-white/35">{device?.mgmt_ip}</span>
            </span>

            {/* Ping-only -> no interface to pick; the link binds to the device itself. */}
            {pingOnly ? (
                <div className={`${field} flex items-center gap-2 text-white/45`}>
                    Ping-only device - linked without an interface
                </div>
            ) : empty ? (
                <div className="space-y-1.5">
                    <button
                        type="button"
                        disabled={!device || discover.isPending}
                        onClick={() => device && discover.mutate({ id: device.id, name: device.name })}
                        className={`${field} flex items-center justify-center gap-2 text-emerald-300 hover:text-emerald-200`}
                    >
                        <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0" />
                        <span>{discover.isPending ? 'Discovering...' : 'No interfaces - discover now'}</span>
                    </button>
                    {device?.discovery_error && (
                        <p className="px-1 text-[11px] leading-snug text-rose-400/80">{device.discovery_error}</p>
                    )}
                </div>
            ) : (
                /* Custom listbox (not a native <select>) so each option can show the
                   interface description as smaller text beneath its name. */
                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        className={`${field} flex items-center justify-between gap-2 text-left`}
                    >
                    <span className={`truncate ${selected ? 'text-white' : 'text-white/45'}`}>{buttonText}</span>
                    <CaretDown weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                </button>

                {open && !empty && (ifs.data?.length ?? 0) > 0 && (
                    <>
                        {/* Click-away catcher. */}
                        <button
                            type="button"
                            aria-hidden
                            tabIndex={-1}
                            className="fixed inset-0 z-10 cursor-default"
                            onClick={() => setOpen(false)}
                        />
                        <ul className="absolute z-20 mt-1.5 max-h-56 w-full overflow-auto rounded-xl bg-[#0d0d11] p-1 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                            {ifs.data?.map((i) => {
                                const s = speedLabel(i.speed_mbps);
                                const isSel = String(i.id) === value;
                                return (
                                    <li key={i.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                onChange(String(i.id));
                                                setOpen(false);
                                            }}
                                            className={`w-full rounded-lg px-3 py-2 text-left transition-colors duration-200 ease-fluid hover:bg-white/5 ${
                                                isSel ? 'bg-emerald-500/10 ring-1 ring-emerald-400/20' : ''
                                            }`}
                                        >
                                            <span className="flex items-baseline justify-between gap-2">
                                                <span className="truncate text-sm text-white/90">{i.name}</span>
                                                {s && <span className="shrink-0 font-mono text-[11px] text-white/40">{s}</span>}
                                            </span>
                                            {i.description && (
                                                <span className="mt-0.5 block truncate text-[11px] text-white/45">
                                                    {i.description}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                    )}
                </div>
            )}
        </label>
    );
}

/** Searchable device picker for the far end of a link. Lists every device except the
 *  near end (and any already linked to it), so a peer on a *different map* can be chosen -
 *  the resulting link renders as a portal on each map. */
function PeerPicker({
    devices,
    value,
    onChange,
}: {
    devices: Device[];
    value: Device | null;
    onChange: (d: Device) => void;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const needle = q.trim().toLowerCase();
    const matches = needle
        ? devices.filter((d) => d.name.toLowerCase().includes(needle) || (d.mgmt_ip ?? '').includes(needle))
        : devices;

    return (
        <label className="block space-y-1.5">
            <span className="px-1 text-[11px] font-medium text-white/55">Far-end device</span>
            <div className="relative">
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    className={`${field} flex items-center justify-between gap-2 text-left`}
                >
                    <span className={`truncate ${value ? 'text-white' : 'text-white/45'}`}>
                        {value ? value.name : 'Select a device on any map'}
                    </span>
                    <CaretDown weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                </button>

                {open && (
                    <>
                        <button
                            type="button"
                            aria-hidden
                            tabIndex={-1}
                            className="fixed inset-0 z-10 cursor-default"
                            onClick={() => setOpen(false)}
                        />
                        <div className="absolute z-20 mt-1.5 w-full rounded-xl bg-[#0d0d11] p-1 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                            <div className="flex items-center gap-2 px-2 py-1.5">
                                <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                                <input
                                    autoFocus
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Search name or IP"
                                    className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                                />
                            </div>
                            <ul className="max-h-56 overflow-auto">
                                {matches.length === 0 ? (
                                    <li className="px-3 py-2 text-xs text-white/35">No matching devices</li>
                                ) : (
                                    matches.slice(0, 60).map((d) => (
                                        <li key={d.id}>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    onChange(d);
                                                    setOpen(false);
                                                }}
                                                className={`w-full rounded-lg px-3 py-2 text-left transition-colors duration-200 ease-fluid hover:bg-white/5 ${
                                                    value?.id === d.id ? 'bg-emerald-500/10 ring-1 ring-emerald-400/20' : ''
                                                }`}
                                            >
                                                <span className="flex items-baseline justify-between gap-2">
                                                    <span className="truncate text-sm text-white/90">{d.name}</span>
                                                    <span className="shrink-0 font-mono text-[11px] text-white/40">{d.mgmt_ip}</span>
                                                </span>
                                            </button>
                                        </li>
                                    ))
                                )}
                            </ul>
                        </div>
                    </>
                )}
            </div>
        </label>
    );
}

/** Create a link starting from one fixed device (the inspector's selection), choosing the
 *  far end from any device - including one on a different map. Same create endpoint as the
 *  drag binder; only the far-end selection differs. */
export function AddLinkDialog({
    aDevice,
    devices,
    onClose,
}: {
    aDevice: Device;
    devices: Device[];
    onClose: () => void;
}) {
    const isAdmin = useIsAdmin();
    const [bDev, setBDev] = useState<Device | null>(null);
    const [aIf, setAIf] = useState('');
    const [bIf, setBIf] = useState('');
    const create = useCreateLink();
    const busy = create.isPending;
    const isError = create.isError;
    const aPingOnly = aDevice.poll_method === 'none';
    const bPingOnly = bDev?.poll_method === 'none';
    const ready = bDev !== null && (aPingOnly || aIf !== '') && (bPingOnly || bIf !== '') && !busy;

    // Every device except the near end - a peer already linked here just fails the
    // duplicate check on submit, which is friendlier than silently hiding it.
    const candidates = devices.filter((d) => d.id !== aDevice.id);

    if (!isAdmin) return null;

    function confirm() {
        if (!bDev) return;
        if ((!aPingOnly && aIf === '') || (!bPingOnly && bIf === '')) return;
        create.mutate(
            {
                a_device_id: aDevice.id,
                a_interface_id: aPingOnly ? null : Number(aIf),
                b_device_id: bDev.id,
                b_interface_id: bPingOnly ? null : Number(bIf),
            },
            { onSuccess: onClose },
        );
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <LinkSimple weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Add link</h2>
                                <p className="text-xs text-white/40">Link to a device on this or any other map</p>
                            </div>
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-1 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                        >
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <div className="space-y-3.5">
                        <EndPicker device={aDevice} value={aIf} onChange={setAIf} />
                        <PeerPicker devices={candidates} value={bDev} onChange={setBDev} />
                        {bDev && <EndPicker device={bDev} value={bIf} onChange={setBIf} />}
                    </div>

                    {isError && (
                        <p className="mt-3 text-xs text-rose-400/90">
                            Couldn't create the link - they may already be linked, or an interface doesn't match its device.
                        </p>
                    )}

                    <div className="mt-6 flex items-center justify-end gap-2.5">
                        <button
                            onClick={onClose}
                            className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors duration-300 ease-fluid hover:text-white/90"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={confirm}
                            disabled={!ready}
                            className="group flex items-center gap-2 rounded-full bg-emerald-500 py-2 pl-5 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                        >
                            <span>{busy ? 'Linking...' : 'Create link'}</span>
                            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5">
                                <LinkSimple weight="bold" className="h-4 w-4" />
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function LinkBinderDialog({
    pending,
    devices,
    onClose,
}: {
    pending: PendingLink; // drag A->B -> create. (Editing an existing link lives in the link-history Edit tab.)
    devices: Device[];
    onClose: () => void;
}) {
    const isAdmin = useIsAdmin();
    const aDev = devices.find((d) => d.id === pending.aDeviceId);
    const bDev = devices.find((d) => d.id === pending.bDeviceId);
    const [aIf, setAIf] = useState('');
    const [bIf, setBIf] = useState('');
    const create = useCreateLink();
    const busy = create.isPending;
    const isError = create.isError;
    // A ping-only end contributes no interface, so it's ready without a selection.
    const aPingOnly = aDev?.poll_method === 'none';
    const bPingOnly = bDev?.poll_method === 'none';
    const ready = (aPingOnly || aIf !== '') && (bPingOnly || bIf !== '') && !busy;

    // Read-only viewers can\'t create links (the backend 403s it); render nothing
    // actionable if the dialog is somehow reached.
    if (!isAdmin) return null;

    function confirm() {
        if ((!aPingOnly && aIf === '') || (!bPingOnly && bIf === '')) return;
        create.mutate(
            {
                a_device_id: pending.aDeviceId,
                a_interface_id: aPingOnly ? null : Number(aIf),
                b_device_id: pending.bDeviceId,
                b_interface_id: bPingOnly ? null : Number(bIf),
            },
            { onSuccess: onClose },
        );
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            {/* Backdrop - a fixed element, so glass blur is allowed here (never on the canvas). */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <LinkSimple weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Link interfaces</h2>
                                <p className="text-xs text-white/40">Bind each end to draw a live link</p>
                            </div>
                        </div>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-1 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                        >
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <div className="space-y-3.5">
                        <EndPicker device={aDev} value={aIf} onChange={setAIf} />
                        <EndPicker device={bDev} value={bIf} onChange={setBIf} />
                    </div>

                    {isError && (
                        <p className="mt-3 text-xs text-rose-400/90">
                            Couldn't create the link - they may already be linked, or an interface doesn't match its device.
                        </p>
                    )}

                    <div className="mt-6 flex items-center justify-end gap-2.5">
                        <button
                            onClick={onClose}
                            className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors duration-300 ease-fluid hover:text-white/90"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={confirm}
                            disabled={!ready}
                            className="group flex items-center gap-2 rounded-full bg-emerald-500 py-2 pl-5 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                        >
                            <span>{busy ? 'Linking...' : 'Create link'}</span>
                            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5">
                                <LinkSimple weight="bold" className="h-4 w-4" />
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
