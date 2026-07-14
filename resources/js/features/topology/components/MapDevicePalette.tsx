import { useState } from 'react';
import { MagnifyingGlass, Plus, Stack } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useMap, useAddDeviceToMap, useSaveMapPosition } from '../../maps/api/maps';
import { useActiveMapId, selectDevice } from '../../../lib/shellStore';
import { useIsAdmin } from '../../auth/api/auth';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceTypeBadge } from '../../../components/DeviceTypeBadge';

/**
 * The map-tools panel shown in the inspector when nothing is selected: a searchable list of
 * devices not yet on the current map. Drag one onto the canvas to place it precisely (handled
 * by MapCanvas's onDrop), or click to drop it in. Admin-only.
 */
export function MapDevicePalette() {
    const isAdmin = useIsAdmin();
    const activeMapId = useActiveMapId();
    const { data: devices } = useDevices();
    const { data: mapDetail } = useMap(activeMapId);
    const addToMap = useAddDeviceToMap();
    const savePosition = useSaveMapPosition();
    const [q, setQ] = useState('');

    if (!isAdmin) {
        return <div className="grid flex-1 place-items-center px-6 text-center text-sm text-white/35">Select a device on the map.</div>;
    }

    const placed = new Set((mapDetail?.positions ?? []).map((p) => p.device_id));
    const query = q.trim().toLowerCase();
    const offMap = (devices ?? [])
        .filter((d) => !placed.has(d.id))
        .filter((d) => !query || d.name.toLowerCase().includes(query) || d.mgmt_ip.includes(query));

    function place(deviceId: number) {
        if (activeMapId === null) return;
        // Click-to-place drops it near the top-left with a little scatter so repeats don't stack.
        const x = 120 + Math.random() * 260;
        const y = 120 + Math.random() * 200;
        addToMap.mutate(
            { mapId: activeMapId, deviceId },
            { onSuccess: () => { savePosition.mutate({ mapId: activeMapId, deviceId, x, y }); selectDevice(deviceId); } },
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3 p-4">
            <div>
                <h2 className="flex items-center gap-2 text-sm font-bold text-white">
                    <Stack weight="light" className="h-4 w-4 text-white/50" /> Map tools
                </h2>
                <p className="mt-0.5 text-xs text-white/40">Drag a device onto the map to place it, or click to drop it in. Select a placed device to inspect it.</p>
            </div>

            <div className="relative">
                <MagnifyingGlass weight="bold" className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Search devices not on this map..."
                    className="w-full rounded-lg bg-white/[0.04] py-1.5 pl-8 pr-3 text-xs text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40 placeholder:text-white/30"
                />
            </div>

            {activeMapId === null ? (
                <p className="text-xs text-white/40">Open a map to place devices.</p>
            ) : offMap.length === 0 ? (
                <p className="text-xs text-white/40">{query ? 'No matches.' : 'Every device is already on this map.'}</p>
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
                </ul>
            )}
        </div>
    );
}
