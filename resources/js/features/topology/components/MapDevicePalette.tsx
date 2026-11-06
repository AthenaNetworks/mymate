import { useState } from 'react';
import { CaretDoubleRight, LinkBreak, MagnifyingGlass, Plus, Stack } from '@phosphor-icons/react';
import { useDebounced, useDeviceList } from '../../devices/api/getDevices';
import { useAddDeviceToMap } from '../../maps/api/maps';
import { useActiveMapId, selectDevice, setMapToolsCollapsed, useMapToolsOrphansOnly, setMapToolsOrphansOnly } from '../../../lib/shellStore';
import { useIsAdmin } from '../../auth/api/auth';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceTypeBadge } from '../../../components/DeviceTypeBadge';

const RESULTS = 100;

/**
 * The map-tools panel shown in the inspector when nothing is selected: a searchable list of
 * devices not yet on the current map. Drag one onto the canvas to place it precisely (handled
 * by MapCanvas's onDrop), or click to drop it in. Admin-only. The "Orphaned only" toggle narrows
 * the list to devices on no map at all.
 */
export function MapDevicePalette() {
    const isAdmin = useIsAdmin();
    const activeMapId = useActiveMapId();
    const addToMap = useAddDeviceToMap();
    const orphansOnly = useMapToolsOrphansOnly();
    const [q, setQ] = useState('');
    const query = useDebounced(q.trim());
    // Searched server-side (GitHub #22); a page is plenty for a drag-and-drop palette.
    const { data } = useDeviceList(
        {
            q: query || undefined,
            not_on_map: activeMapId ?? undefined,
            // placed=0: on no map at all, which already implies not on this one.
            placed: orphansOnly ? false : undefined,
            per_page: RESULTS,
            fields: 'summary',
        },
        { enabled: isAdmin && activeMapId !== null },
    );

    if (!isAdmin) {
        return <div className="grid flex-1 place-items-center px-6 text-center text-sm text-white/35">Select a device on the map.</div>;
    }

    const offMap = data?.data ?? [];
    const more = (data?.meta.total ?? 0) - offMap.length;

    function place(deviceId: number) {
        if (activeMapId === null) return;
        // Click-to-place drops it near the top-left with a little scatter so repeats don't stack.
        const x = 120 + Math.random() * 260;
        const y = 120 + Math.random() * 200;
        addToMap.mutate({ mapId: activeMapId, deviceId, x, y }, { onSuccess: () => selectDevice(deviceId) });
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3 p-4">
            <div>
                <h2 className="flex items-center gap-2 text-sm font-bold text-white">
                    <Stack weight="light" className="h-4 w-4 text-white/50" /> Map tools
                    {/* Desktop only: below lg the panel is a slide-over sheet with its own close. */}
                    <button
                        onClick={() => setMapToolsCollapsed(true)}
                        title="Collapse map tools"
                        className="ml-auto hidden rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 lg:block"
                    >
                        <CaretDoubleRight weight="bold" className="h-4 w-4" />
                    </button>
                </h2>
                <p className="mt-0.5 text-xs text-white/40">Drag a device onto the map to place it, or click to drop it in. Select a placed device to inspect it.</p>
            </div>

            <div className="relative">
                <MagnifyingGlass weight="bold" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={orphansOnly ? 'Search devices on no map...' : 'Search devices not on this map...'}
                    className="w-full rounded-lg bg-white/[0.04] py-1.5 pl-8 pr-3 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40 placeholder:text-white/30"
                />
            </div>

            <div className="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    onClick={() => setMapToolsOrphansOnly(!orphansOnly)}
                    aria-pressed={orphansOnly}
                    title="Show only devices that aren't on any map"
                    className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 transition-colors duration-300 ease-fluid ${
                        orphansOnly
                            ? 'bg-emerald-400/15 text-emerald-200 ring-emerald-400/40'
                            : 'bg-white/[0.03] text-white/50 ring-white/10 hover:bg-white/[0.06] hover:text-white/80'
                    }`}
                >
                    <LinkBreak weight="bold" className="h-3 w-3" /> Orphaned only
                </button>
            </div>

            {activeMapId === null ? (
                <p className="text-xs text-white/40">Open a map to place devices.</p>
            ) : offMap.length === 0 ? (
                <p className="text-xs text-white/40">{query ? 'No matches.' : orphansOnly ? 'Every device is on at least one map.' : 'Every device is already on this map.'}</p>
            ) : (
                <ul className="min-h-0 flex-1 space-y-0.5 overflow-y-auto">
                    {offMap.map((d) => (
                        <li
                            key={d.id}
                            draggable
                            onDragStart={(e) => {
                                e.dataTransfer.setData('application/mymate-device', String(d.id));
                                e.dataTransfer.effectAllowed = 'move';
                            }}
                            onClick={() => place(d.id)}
                            title="Drag onto the map, or click to place"
                            className="group flex cursor-grab items-center gap-2.5 rounded-lg px-2 py-1.5 ring-1 ring-transparent transition hover:bg-white/[0.04] hover:ring-white/10 active:cursor-grabbing"
                        >
                            <StatusDot status={d.status} />
                            <DeviceTypeBadge type={d.device_type} className="h-5 w-6 shrink-0" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-xs font-medium text-white/85">{d.name}</span>
                                <span className="block truncate font-mono text-[10px] text-white/35">{d.mgmt_ip}</span>
                            </span>
                            <Plus weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/25 transition group-hover:text-emerald-300" />
                        </li>
                    ))}
                    {more > 0 && <li className="px-2 py-1.5 text-[11px] text-white/30">{more} more - search to narrow it down.</li>}
                </ul>
            )}
        </div>
    );
}
