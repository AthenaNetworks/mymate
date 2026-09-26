import { useState } from 'react';
import { CaretDown, MagnifyingGlass } from '@phosphor-icons/react';
import { useDebounced, useDeviceList, type DeviceListParams } from '../features/devices/api/getDevices';
import { StatusDot } from './StatusDot';
import type { Device } from '../types';

const defaultField =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

const RESULTS = 30;

/** A device the picker can show as its current value - an id and something to call it. */
export type PickedDevice = Pick<Device, 'id' | 'name'> & Partial<Device>;

/**
 * Typeahead device picker (GitHub #22). Searches the server as you type instead of filtering a
 * fleet-sized list in the browser, so it works the same at 20 devices or 25,000. `params` narrows
 * the candidates (e.g. SNMP only, or not below a given device); `noneLabel` adds a "none" row.
 */
export function DevicePicker({
    value,
    onChange,
    params,
    placeholder = 'Select a device',
    noneLabel,
    excludeId,
    className = defaultField,
    disabled,
}: {
    value: PickedDevice | null;
    onChange: (d: Device | null) => void;
    params?: DeviceListParams;
    placeholder?: string;
    noneLabel?: string;
    excludeId?: number;
    className?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const needle = useDebounced(q.trim());
    const { data, isFetching } = useDeviceList(
        { ...params, q: needle || undefined, per_page: RESULTS, fields: 'summary' },
        { enabled: open },
    );
    const matches = (data?.data ?? []).filter((d) => d.id !== excludeId);
    const more = (data?.meta.total ?? 0) > RESULTS;

    const pick = (d: Device | null) => {
        onChange(d);
        setOpen(false);
        setQ('');
    };

    return (
        <div className="relative">
            <button
                type="button"
                disabled={disabled}
                onClick={() => setOpen((o) => !o)}
                className={`${className} flex items-center justify-between gap-2 text-left disabled:opacity-50`}
            >
                <span className={`truncate ${value ? 'text-white' : 'text-white/45'}`}>{value ? value.name : noneLabel ?? placeholder}</span>
                <CaretDown weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
            </button>

            {open && (
                <>
                    <button type="button" aria-hidden tabIndex={-1} className="fixed inset-0 z-10 cursor-default" onClick={() => setOpen(false)} />
                    <div className="absolute z-20 mt-1.5 w-full min-w-[16rem] rounded-xl bg-surface p-1 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                        <div className="flex items-center gap-2 px-2 py-1.5">
                            <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                            <input
                                autoFocus
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search name, IP, model..."
                                className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                            />
                        </div>
                        <ul className="max-h-56 overflow-auto">
                            {noneLabel && (
                                <li>
                                    <button type="button" onClick={() => pick(null)} className="w-full rounded-lg px-3 py-2 text-left text-sm text-white/60 hover:bg-white/5">
                                        {noneLabel}
                                    </button>
                                </li>
                            )}
                            {!data && isFetching ? (
                                <li className="px-3 py-2 text-xs text-white/35">Searching...</li>
                            ) : matches.length === 0 ? (
                                <li className="px-3 py-2 text-xs text-white/35">No matching devices</li>
                            ) : (
                                matches.map((d) => (
                                    <li key={d.id}>
                                        <button
                                            type="button"
                                            onClick={() => pick(d)}
                                            className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left transition-colors duration-200 ease-fluid hover:bg-white/5 ${
                                                value?.id === d.id ? 'bg-emerald-500/10 ring-1 ring-emerald-400/20' : ''
                                            }`}
                                        >
                                            <StatusDot status={d.status} />
                                            <span className="min-w-0 flex-1 truncate text-sm text-white/90">{d.name}</span>
                                            <span className="shrink-0 font-mono text-[11px] text-white/40">{d.mgmt_ip}</span>
                                        </button>
                                    </li>
                                ))
                            )}
                            {more && <li className="px-3 py-1.5 text-[11px] text-white/30">Showing the first {RESULTS} - type to narrow it down.</li>}
                        </ul>
                    </div>
                </>
            )}
        </div>
    );
}
