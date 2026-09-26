import { useEffect, useState } from 'react';
import { ArrowLeft, MapPin, Terminal } from '@phosphor-icons/react';
import { navigateTo, setView } from '../../../lib/shellStore';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceGlyph } from '../../topology/nodes/DeviceGlyph';
import { useCurrentUser } from '../../auth/api/auth';
import { useDevicePageDevice, useDeviceSummary } from '../api/devicePage';
import { useDeviceLive } from '../api/useDeviceLive';
import { clearRangeHistory } from '../lib/range';
import { parseDeviceRoute, setQuery, showOnMap, useLocationHref } from '../lib/location';
import { OverviewTab } from './OverviewTab';
import { GraphsTab } from './GraphsTab';
import { PortsTab } from './PortsTab';
import { PortPage } from './PortPage';
import { EventsTab } from './EventsTab';
import { ConfigTab } from './ConfigTab';
import { actionBtn } from './ui';
import type { DeviceStatus } from '../../../types';

type Tab = 'overview' | 'graphs' | 'ports' | 'events' | 'config';

const statusBadge: Record<DeviceStatus, { label: string; cls: string }> = {
    up: { label: 'Operational', cls: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    down: { label: 'Down', cls: 'bg-rose-500/15 text-rose-300 ring-rose-400/25' },
    unknown: { label: 'Unknown', cls: 'bg-white/5 text-white/45 ring-white/10' },
};

/**
 * The full device page (GitHub #28), LibreNMS style: /devices/{id} with Overview, Graphs,
 * Ports, Events and Config tabs, and /devices/{id}/ports/{ifId} for one port with its billing.
 * Which device, port, tab and graph range are all in the URL, so any view can be linked.
 */
export function DevicePage() {
    const href = useLocationHref();
    const route = parseDeviceRoute(href);

    if (route.deviceId === null) {
        return <div className="grid h-full place-items-center text-sm text-white/40">No device in this link.</div>;
    }
    return <DevicePageBody key={route.deviceId} deviceId={route.deviceId} portId={route.portId} params={route.params} />;
}

function DevicePageBody({ deviceId, portId, params }: { deviceId: number; portId: number | null; params: URLSearchParams }) {
    const { data: device, isLoading, error } = useDevicePageDevice(deviceId);
    const { data: summary } = useDeviceSummary(deviceId);
    const { data: me } = useCurrentUser();
    const restricted = !!me?.restricted;
    const [scrollEl, setScrollEl] = useState<HTMLDivElement | null>(null);
    useDeviceLive(deviceId);

    // each device starts with a clean zoom history
    useEffect(() => clearRangeHistory(), [deviceId]);

    const tabs: { id: Tab; label: string }[] = [
        { id: 'overview', label: 'Overview' },
        { id: 'graphs', label: 'Graphs' },
        { id: 'ports', label: 'Ports' },
        { id: 'events', label: 'Events' },
        ...(restricted ? [] : [{ id: 'config' as Tab, label: 'Config' }]),
    ];
    const requested = params.get('tab') as Tab | null;
    const tab: Tab = portId !== null ? 'ports' : tabs.some((t) => t.id === requested) ? (requested as Tab) : 'overview';

    // new tab or port, back to the top
    useEffect(() => {
        scrollEl?.scrollTo({ top: 0 });
    }, [scrollEl, tab, portId]);

    if (isLoading) return <div className="grid h-full place-items-center text-sm text-white/35">Loading device...</div>;
    if (error || !device) {
        return (
            <div className="grid h-full place-items-center px-6 text-center text-sm text-white/40">
                <div className="space-y-3">
                    <p>This device doesn't exist, or you don't have access to it.</p>
                    <button type="button" onClick={() => setView('devices')} className={`${actionBtn} mx-auto`}>
                        <ArrowLeft weight="bold" className="h-3.5 w-3.5" /> All devices
                    </button>
                </div>
            </div>
        );
    }

    const badge = statusBadge[device.status] ?? statusBadge.unknown;
    const firstMap = summary?.maps[0]?.id ?? null;

    return (
        <div ref={setScrollEl} className="h-full overflow-y-auto">
            <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
                <header className="space-y-4">
                    <button type="button" onClick={() => setView('devices')} className="flex items-center gap-1.5 text-xs text-white/40 transition-colors hover:text-white/80">
                        <ArrowLeft weight="bold" className="h-3.5 w-3.5" /> Devices
                    </button>
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl ring-1 ring-white/10">
                            <DeviceGlyph deviceId={device.id} vendor={device.vendor} model={device.model} type={device.device_type} icon={device.icon} iconColor={device.icon_color} className="h-7 w-7" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <h1 className="truncate text-xl font-bold tracking-tight text-white">{device.name}</h1>
                            <p className="truncate text-xs text-white/40">
                                <span className="font-mono">{device.mgmt_ip ?? 'no management IP'}</span>
                                {[device.vendor, device.model, device.os_version].filter(Boolean).length > 0 && ` - ${[device.vendor, device.model, device.os_version].filter(Boolean).join(' ')}`}
                            </p>
                        </div>
                        <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${badge.cls}`}>
                            <StatusDot status={device.status} /> {badge.label}
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {device.mgmt_ip && (
                                <>
                                    <a href={`winbox://${device.mgmt_ip}`} className={actionBtn}>
                                        Winbox
                                    </a>
                                    <a href={`ssh://${device.mgmt_ip}`} className={actionBtn}>
                                        <Terminal weight="light" className="h-3.5 w-3.5" /> SSH
                                    </a>
                                </>
                            )}
                            <button
                                type="button"
                                onClick={() => showOnMap(device.id, firstMap)}
                                disabled={summary !== undefined && summary.maps.length === 0}
                                title={summary && summary.maps.length === 0 ? 'Not placed on any map' : 'Show on map'}
                                className={actionBtn}
                            >
                                <MapPin weight="light" className="h-3.5 w-3.5" /> Show on map
                            </button>
                        </div>
                    </div>

                    <nav className="flex gap-1 overflow-x-auto border-b border-white/10">
                        {tabs.map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => {
                                    if (portId !== null) {
                                        // leave the port page for the device tab, keeping the range
                                        const q = new URLSearchParams(params);
                                        if (t.id === 'overview') q.delete('tab');
                                        else q.set('tab', t.id);
                                        navigateTo(`/devices/${deviceId}${q.toString() ? `?${q}` : ''}`);
                                    } else {
                                        setQuery({ tab: t.id === 'overview' ? null : t.id }, { push: true });
                                    }
                                }}
                                className={`-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-200 ease-fluid ${
                                    tab === t.id ? 'border-emerald-400 text-white' : 'border-transparent text-white/45 hover:text-white/80'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </nav>
                </header>

                {portId !== null ? (
                    <PortPage device={device} portId={portId} params={params} />
                ) : tab === 'overview' ? (
                    <OverviewTab device={device} summary={summary} />
                ) : tab === 'graphs' ? (
                    <GraphsTab device={device} params={params} />
                ) : tab === 'ports' ? (
                    <PortsTab device={device} params={params} />
                ) : tab === 'events' ? (
                    <EventsTab device={device} />
                ) : (
                    <ConfigTab device={device} />
                )}
            </div>
        </div>
    );
}
