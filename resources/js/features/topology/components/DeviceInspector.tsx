import { useEffect, useState, type ReactNode } from 'react';
import { ArrowsOut, CaretDown, CaretLeft, CaretRight, Check, CircleNotch, LinkSimple, MagnifyingGlass, Path, PencilSimple, Terminal, Trash, X } from '@phosphor-icons/react';
import {
    useSelectedDeviceId,
    selectDevice,
    useActiveMapId,
    useDeviceChartMode,
    setDeviceChartMode,
    useDeviceIfaceFilter,
    setDeviceIfaceFilter,
    useInspectorOpen,
    setInspectorOpen,
    type IfaceFilter,
} from '../../../lib/shellStore';
import { useDevices } from '../../devices/api/getDevices';
import { useDeviceInterfaces } from '../api/getDeviceInterfaces';
import { useDiscoverDevice } from '../api/discoverDevice';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useUpgradeDevices } from '../../devices/api/upgradeDevices';
import { useCredentials } from '../../settings/api/credentials';
import { useLinks } from '../api/getLinks';
import { useDeleteLink } from '../api/deleteLink';
import { useMap, useAddDeviceToMap, useRemoveDeviceFromMap } from '../../maps/api/maps';
import { LinkHistoryDialog } from './LinkHistoryDialog';
import { AddLinkDialog } from './LinkBinderDialog';
import { DeviceDialog } from '../../devices/components/DeviceDialog';
import { ChartModal } from './ChartModal';
import { TraceModal } from './TraceModal';
import { BackupSection } from '../../backups/components/BackupSection';
import { DeviceResources } from './DeviceResources';
import { ConfirmDialog } from '../../../components/Dialog';
import { MapDevicePalette } from './MapDevicePalette';
import { pushToast } from '../../../lib/toast';
import { useDeviceSamples } from '../api/getDeviceSamples';
import { useIsAdmin } from '../../auth/api/auth';
import { InterfaceChart } from './InterfaceChart';
import { linkColor } from '../lib/linkColor';
import { StatusDot } from '../../../components/StatusDot';
import { DeviceTypeBadge } from '../../../components/DeviceTypeBadge';
import { DeviceGlyph } from '../nodes/DeviceGlyph';
import { UpgradeStatusBadge } from '../../../components/UpgradeStatusBadge';
import { relativeTime } from '../../../lib/relativeTime';
import { formatMbps, formatRate } from '../../../lib/formatRate';
import { UPGRADE_IN_PROGRESS, type Device, type DeviceStatus, type DeviceType, type Link, type NetworkInterface, type PollMethod } from '../../../types';

const pollLabel: Record<string, string> = { snmp: 'SNMP v2c', routeros: 'RouterOS API', none: 'Ping only' };

const statusBadge: Record<DeviceStatus, { label: string; cls: string }> = {
    up: { label: 'Operational', cls: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    down: { label: 'Down', cls: 'bg-rose-500/15 text-rose-300 ring-rose-400/25' },
    unknown: { label: 'Unknown', cls: 'bg-white/5 text-white/45 ring-white/10' },
};

const actionBtn =
    'flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white';

function fmtBytes(b: number): string {
    if (b >= 1024 ** 3) return `${(b / 1024 ** 3).toFixed(b < 10 * 1024 ** 3 ? 1 : 0)} GB`;
    if (b >= 1024 ** 2) return `${Math.round(b / 1024 ** 2)} MB`;
    return `${Math.round(b / 1024)} KB`;
}

function fmtUptime(seconds: number | null, at: string | null): string {
    if (seconds === null) return '-';
    const extra = at ? Math.max(0, (Date.now() - Date.parse(at)) / 1000) : 0;
    const total = seconds + extra;
    const d = Math.floor(total / 86400);
    const h = Math.floor((total % 86400) / 3600);
    const m = Math.floor((total % 3600) / 60);
    return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;
}

// Compact load on the busier end ("6.1G", "730M", "12k"); shared rate formatter.
function compactRate(mbps: number | null): string {
    return formatMbps(mbps, { compact: true }) || '-';
}

function speedLabel(mbps: number | null): string {
    if (!mbps) return '-';
    return mbps >= 1000 ? `${(mbps / 1000).toFixed(mbps % 1000 === 0 ? 0 : 1)}G` : `${mbps}M`;
}

/**
 * Read-only interface speed tag. Interface capacity comes from SNMP
 * and is no longer operator-editable - the bandwidth override that drives utilisation now
 * lives on the *link* (set it from the link history/edit dialog). This just shows the
 * negotiated port speed for context next to the per-port load bar.
 */
function SpeedTag({ iface }: { iface: NetworkInterface }) {
    return (
        <span
            className="shrink-0 rounded px-1 py-0.5 font-mono text-[10px] text-white/45 ring-1 ring-white/10"
            title="Interface speed (read-only, from SNMP). Set a link's bandwidth in its history dialog."
        >
            {iface.speed_mbps ? speedLabel(iface.speed_mbps) : '-'}
        </span>
    );
}

function Detail({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">{label}</p>
            <p className={`mt-0.5 truncate text-sm text-white/85 ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</p>
        </div>
    );
}

const POLL_METHOD_OPTIONS: { value: PollMethod; label: string }[] = [
    { value: 'snmp', label: 'SNMP v2c' },
    { value: 'routeros', label: 'RouterOS API' },
    { value: 'none', label: 'Ping only' },
];

// Editable "Poll method" cell: lets an operator drop an SNMP-less leaf
// device to ping-only (no throughput) - or back - right from the inspector.
function PollMethodPicker({ device }: { device: Device }) {
    const update = useUpdateDevice();
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">Poll method</p>
            <select
                value={device.poll_method}
                onChange={(e) => {
                    const value = e.target.value as PollMethod;
                    if (value === device.poll_method) return;
                    update.mutate(
                        { id: device.id, poll_method: value },
                        { onError: () => pushToast({ title: 'Couldn\'t change poll method', tone: 'down' }) },
                    );
                }}
                className="-ml-1 mt-0.5 w-full truncate rounded-md bg-white/[0.03] px-1 py-0.5 text-sm text-white/85 outline-none ring-1 ring-white/10 transition hover:ring-white/25 focus:ring-emerald-400/50"
            >
                {POLL_METHOD_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                ))}
            </select>
        </div>
    );
}

// Editable "Credential" cell: assign the SNMP community / RouterOS login the poller
// authenticates with. Hidden for ping-only devices (nothing to authenticate). Without a
// credential, interface discovery fails with "no SNMP community", so this is the fix.
function CredentialPicker({ device }: { device: Device }) {
    const update = useUpdateDevice();
    const { data: credentials } = useCredentials();
    const matching = (credentials ?? []).filter((c) => c.type === device.poll_method);

    return (
        <div className="min-w-0">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">Credential</p>
            <select
                value={device.credential_id ?? ''}
                onChange={(e) => {
                    const value = e.target.value === '' ? null : Number(e.target.value);
                    if (value === device.credential_id) return;
                    update.mutate(
                        { id: device.id, credential_id: value },
                        { onError: () => pushToast({ title: 'Couldn\'t change credential', tone: 'down' }) },
                    );
                }}
                className="-ml-1 mt-0.5 w-full truncate rounded-md bg-white/[0.03] px-1 py-0.5 text-sm text-white/85 outline-none ring-1 ring-white/10 transition hover:ring-white/25 focus:ring-emerald-400/50"
            >
                <option value="">{matching.length > 0 ? 'None' : 'None - add one in Settings'}</option>
                {matching.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                ))}
            </select>
        </div>
    );
}

function Section({ title, right, children }: { title: string; right?: string; children: ReactNode }) {
    return (
        <div className="space-y-2.5">
            <div className="flex items-baseline justify-between">
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">{title}</p>
                {right ? <span className="font-mono text-xs tabular-nums text-white/60">{right}</span> : null}
            </div>
            {children}
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">{children}</p>
    );
}

const IFACE_PAGE = 12;
const IFACE_FILTERS: { key: IfaceFilter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'linked', label: 'Linked' },
    { key: 'traffic', label: 'Traffic' },
];

/**
 * Interfaces list with search - filter chips - pagination. A switch/router can have
 * dozens of ports, so the full set is filterable and paged ({@link IFACE_PAGE}/page)
 * rather than truncated. The filter is remembered per device (in the store); search +
 * page are transient and reset when the inspector switches device.
 */
function InterfacesList({
    deviceId,
    ifaces,
    peerName,
}: {
    deviceId: number;
    ifaces: NetworkInterface[];
    peerName: (id: number) => string | null;
}) {
    const [query, setQuery] = useState('');
    const filter = useDeviceIfaceFilter(deviceId); // remembered per device
    const [page, setPage] = useState(0);

    // Search + page are transient - clear them when the selected device changes.
    useEffect(() => {
        setQuery('');
        setPage(0);
    }, [deviceId]);

    const q = query.trim().toLowerCase();
    const filtered = ifaces.filter((i) => {
        if (filter === 'linked' && peerName(i.id) === null) return false;
        if (filter === 'traffic' && (i.util_in ?? 0) <= 0 && (i.util_out ?? 0) <= 0) return false;
        if (q === '') return true;
        const peer = peerName(i.id)?.toLowerCase() ?? '';
        return i.name.toLowerCase().includes(q) || (i.description?.toLowerCase().includes(q) ?? false) || peer.includes(q);
    });

    // `current` clamps the page for display; `page` is only ever set to 0 (on
    // search/filter change) or to an in-bounds neighbour (the nav buttons are
    // disabled at the ends), so it never strays out of range.
    const pageCount = Math.max(1, Math.ceil(filtered.length / IFACE_PAGE));
    const current = Math.min(page, pageCount - 1);
    const start = current * IFACE_PAGE;
    const visible = filtered.slice(start, start + IFACE_PAGE);

    function reset(next: () => void) {
        next();
        setPage(0);
    }

    return (
        <div className="space-y-2.5">
            <div className="relative">
                <MagnifyingGlass weight="light" className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-white/30" />
                <input
                    value={query}
                    onChange={(e) => reset(() => setQuery(e.target.value))}
                    placeholder="Search interfaces..."
                    className="w-full rounded-lg bg-white/[0.04] py-1.5 pr-7 pl-8 text-xs text-white ring-1 ring-white/10 transition-colors duration-200 ease-fluid outline-none placeholder:text-white/30 focus:ring-emerald-400/40"
                />
                {query && (
                    <button
                        type="button"
                        onClick={() => reset(() => setQuery(''))}
                        title="Clear search"
                        className="absolute top-1/2 right-2 -translate-y-1/2 text-white/30 transition-colors hover:text-white/70"
                    >
                        <X weight="bold" className="h-3 w-3" />
                    </button>
                )}
            </div>

            <div className="flex items-center gap-1">
                {IFACE_FILTERS.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => {
                            setDeviceIfaceFilter(deviceId, f.key);
                            setPage(0);
                        }}
                        className={`rounded-full px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ring-1 transition-colors duration-200 ease-fluid ${
                            filter === f.key ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' : 'text-white/45 ring-white/10 hover:text-white/75'
                        }`}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            {visible.length === 0 ? (
                <Empty>{q ? `No interfaces match "${query}".` : 'No interfaces match this filter.'}</Empty>
            ) : (
                <div className="space-y-2.5">
                    {visible.map((i) => {
                        const peer = peerName(i.id);
                        const hasU = i.util_in !== null || i.util_out !== null;
                        const u = Math.max(i.util_in ?? 0, i.util_out ?? 0);
                        // Busier-direction raw rate (bits/sec -> Mbps) when we have it; else
                        // fall back to per-port util x the SNMP port speed.
                        const load =
                            i.bps_in !== null || i.bps_out !== null
                                ? Math.max(i.bps_in ?? 0, i.bps_out ?? 0) / 1_000_000
                                : hasU && i.speed_mbps
                                  ? (u / 100) * i.speed_mbps
                                  : null;
                        return (
                            <div key={i.id} className="space-y-1">
                                <div className="flex items-baseline gap-2">
                                    <span className="min-w-0 flex-1 truncate text-xs font-medium text-white/80">
                                        {i.name}
                                        {peer ? <span className="text-white/35"> → {peer}</span> : null}
                                    </span>
                                    <SpeedTag iface={i} />
                                    <span className="w-10 shrink-0 text-right font-mono text-[11px] text-white/75">{compactRate(load)}</span>
                                </div>
                                <div className="h-1 overflow-hidden rounded-full bg-white/10">
                                    <div
                                        className="h-full rounded-full transition-all duration-500 ease-fluid"
                                        style={{
                                            width: `${Math.min(Math.max(hasU ? u : 0, 0), 100)}%`,
                                            background: linkColor(hasU ? u : null, false),
                                        }}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {filtered.length > 0 && (
                <div className="flex items-center justify-between pt-0.5 text-[10px] text-white/35">
                    <span className="tabular-nums">
                        {start + 1}-{Math.min(start + IFACE_PAGE, filtered.length)} of {filtered.length}
                        {filtered.length !== ifaces.length ? ` - ${ifaces.length} total` : ''}
                    </span>
                    {pageCount > 1 && (
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                disabled={current === 0}
                                onClick={() => setPage(current - 1)}
                                title="Previous page"
                                className="rounded-md p-1 text-white/50 ring-1 ring-white/10 transition-colors hover:bg-white/5 hover:text-white disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                            >
                                <CaretLeft weight="bold" className="h-3 w-3" />
                            </button>
                            <span className="tabular-nums text-white/45">
                                {current + 1}/{pageCount}
                            </span>
                            <button
                                type="button"
                                disabled={current >= pageCount - 1}
                                onClick={() => setPage(current + 1)}
                                title="Next page"
                                className="rounded-md p-1 text-white/50 ring-1 ring-white/10 transition-colors hover:bg-white/5 hover:text-white disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                            >
                                <CaretRight weight="bold" className="h-3 w-3" />
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Responsive container for the inspector: a static right-hand column at
 * `lg`+ (unchanged), and a slide-over sheet with a backdrop + close button below it.
 * `open` drives the sheet; at `lg`+ the panel is always shown and `open` is ignored.
 */
function InspectorShell({ open, children }: { open: boolean; children: ReactNode }) {
    return (
        <>
            {/* Backdrop - phone/tablet only; dims the map behind the sheet. */}
            <div
                className={`fixed inset-0 z-30 bg-black/50 backdrop-blur-sm transition-opacity duration-300 ease-fluid lg:hidden ${
                    open ? 'opacity-100' : 'pointer-events-none opacity-0'
                }`}
                onClick={() => setInspectorOpen(false)}
            />
            <aside
                className={`fixed inset-y-0 right-0 z-40 flex w-[min(24rem,88vw)] flex-col gap-5 overflow-y-auto border-l border-white/10 bg-[#0d0d11]/95 p-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] backdrop-blur-2xl transition-transform duration-300 ease-fluid lg:static lg:z-10 lg:w-[22rem] lg:shrink-0 lg:translate-x-0 lg:bg-white/[0.02] lg:shadow-none ${
                    open ? 'translate-x-0' : 'translate-x-full'
                }`}
            >
                {/* Close - phone/tablet only; a top bar so it never overlaps the header. */}
                <div className="flex justify-end lg:hidden">
                    <button
                        onClick={() => setInspectorOpen(false)}
                        title="Close"
                        className="rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </aside>
        </>
    );
}

const DEVICE_TYPES: { value: DeviceType; label: string }[] = [
    { value: 'router', label: 'Router' },
    { value: 'switch', label: 'Switch' },
    { value: 'ap', label: 'Access point' },
    { value: 'server', label: 'Server' },
    { value: 'internet', label: 'Internet' },
    { value: 'unknown', label: 'Unknown' },
];

/** The device-type badge, click to reclassify (RTR/SW/AP/SRV/NET/DEV). */
function ClassificationPicker({ device }: { device: Device }) {
    const [open, setOpen] = useState(false);
    const update = useUpdateDevice();

    function pick(value: DeviceType): void {
        setOpen(false);
        if (value === device.device_type) return;
        update.mutate(
            { id: device.id, device_type: value },
            { onError: () => pushToast({ title: 'Couldn\'t change type', tone: 'down' }) },
        );
    }

    return (
        <div className="relative shrink-0">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                title="Change device classification"
                className="group relative grid h-9 w-9 place-items-center rounded-md ring-1 ring-white/10 transition hover:ring-white/25"
            >
                {/* Same glyph as the map node so a device looks identical in both places. */}
                <DeviceGlyph deviceId={device.id} vendor={device.vendor} model={device.model} type={device.device_type} icon={device.icon} iconColor={device.icon_color} className="h-6 w-6" />
                <CaretDown
                    weight="bold"
                    className="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-[#0d0d11] text-white/40 group-hover:text-white/70"
                />
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
                    <div className="absolute left-0 top-full z-30 mt-1.5 w-44 rounded-xl bg-[#0d0d11] p-1 shadow-[0_18px_50px_-12px_rgba(0,0,0,0.85)] ring-1 ring-white/10">
                        {DEVICE_TYPES.map((t) => (
                            <button
                                key={t.value}
                                onClick={() => pick(t.value)}
                                className="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left text-sm text-white/80 transition-colors duration-200 hover:bg-white/5"
                            >
                                <DeviceTypeBadge type={t.value} className="h-6 w-6" />
                                <span className="flex-1">{t.label}</span>
                                {device.device_type === t.value && <Check weight="bold" className="h-3.5 w-3.5 text-emerald-300" />}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Right-pane device inspector for the map workspace - driven by the shared shell
 * selection. Renders nothing until a node is clicked. Lives in the topology
 * feature because it reuses its data hooks (interfaces/links/samples) + colour ramp.
 * Services are derived from existing signals; outage history is a placeholder until
 * that subsystem lands.
 */
// True once devices have first loaded in this session; gates the auto-home-to-root so an
// explicit deselect (empty-canvas click) sticks and shows the map tools instead.
let autoHomedOnce = false;

export function DeviceInspector() {
    const isAdmin = useIsAdmin();
    const id = useSelectedDeviceId();
    const chartMode = useDeviceChartMode(id ?? 0); // per-device chart toggle
    const inspectorOpen = useInspectorOpen(); // mobile slide-over open state
    const { data: devices } = useDevices();
    const { data: interfaces } = useDeviceInterfaces(id);
    const { data: links } = useLinks();
    const upgrade = useUpgradeDevices();
    const discover = useDiscoverDevice();
    const delLink = useDeleteLink();
    const [editingLink, setEditingLink] = useState<Link | null>(null);
    const [editingDevice, setEditingDevice] = useState(false);
    const [addingLink, setAddingLink] = useState(false);
    const [deletingLink, setDeletingLink] = useState<{ id: number; label: string } | null>(null);
    const [confirmingUpgrade, setConfirmingUpgrade] = useState(false);
    const [chartExpanded, setChartExpanded] = useState(false);
    const [tracing, setTracing] = useState(false);
    const activeMapId = useActiveMapId();
    const { data: mapDetail } = useMap(activeMapId);
    const addToMap = useAddDeviceToMap();
    const removeFromMap = useRemoveDeviceFromMap();
    const device = devices?.find((d) => d.id === id);

    // Default the selection to the upstream/root device ON THE CURRENT MAP on the *first* load
    // (prefer an `internet`/uplink node, else a parentless one) - but only among devices actually
    // placed on this map. A blank map has none, so nothing is selected and the panel shows the
    // map tools/palette instead of snapping onto some off-map device. An explicit deselect
    // (clicking the empty canvas) also leaves the tools showing. A module flag (autoHomedOnce)
    // survives remounts within the session; only a deleted selection re-homes after that.
    useEffect(() => {
        if (!devices || devices.length === 0 || !mapDetail) return;
        const deleted = id !== null && !devices.some((d) => d.id === id);
        if (deleted || (id === null && !autoHomedOnce)) {
            const onMap = new Set((mapDetail.positions ?? []).map((p) => p.device_id));
            const here = devices.filter((d) => onMap.has(d.id));
            const root =
                here.find((d) => d.device_type === 'internet') ??
                here.find((d) => d.parent_device_id === null) ??
                here[0];
            if (root) selectDevice(root.id); // nothing on the map -> stay deselected (show tools)
        }
        autoHomedOnce = true; // after devices first load, a plain deselect no longer re-homes
    }, [id, devices, mapDetail]);

    const ifaces = interfaces ?? [];
    // Device total throughput (sum of every interface\'s latest bps) - shown instead of
    // just the busiest interface. util% is only meaningful when EVERY interface has a
    // known speed, so we hide it otherwise.
    const allHaveSpeed = ifaces.length > 0 && ifaces.every((i) => i.speed_mbps != null);
    const totalBps = ifaces.reduce((sum, i) => sum + (i.bps_in ?? 0) + (i.bps_out ?? 0), 0);
    const { data: samples, isLoading: samplesLoading } = useDeviceSamples(id ?? null, 3600);

    if (!device) {
        return (
            <InspectorShell open={inspectorOpen}>
                {devices && devices.length === 0 ? (
                    <div className="grid flex-1 place-items-center px-6 text-center text-sm text-white/35">
                        No devices yet - add one to see its details here.
                    </div>
                ) : (
                    <MapDevicePalette />
                )}
            </InspectorShell>
        );
    }

    const badge = statusBadge[device.status] ?? statusBadge.unknown;

    // Firmware upgrade - RouterOS only (the binary API drives it); destructive, so confirm.
    const dev = device; // stable non-null capture for the closure below
    const upgrading = !!dev.upgrade_status && UPGRADE_IN_PROGRESS.has(dev.upgrade_status);
    const canUpgrade = dev.poll_method === 'routeros' && !upgrading;
    function doUpgrade() {
        if (!canUpgrade) return;
        setConfirmingUpgrade(true);
    }
    function runUpgrade() {
        upgrade.mutate(
            { deviceIds: [dev.id] },
            {
                onSuccess: () => {
                    pushToast({ title: `${dev.name}: upgrade queued`, detail: 'Downloading + rebooting', tone: 'info' });
                    setConfirmingUpgrade(false);
                },
                onError: () => {
                    pushToast({ title: `${dev.name}: couldn\'t queue upgrade`, tone: 'down' });
                    setConfirmingUpgrade(false);
                },
            },
        );
    }

    const peerName = (ifaceId: number): string | null => {
        const l = (links ?? []).find((x) => x.a_interface_id === ifaceId || x.b_interface_id === ifaceId);
        if (!l) return null;
        const peerDev = l.a_interface_id === ifaceId ? l.b_device_id : l.a_device_id;
        return devices?.find((d) => d.id === peerDev)?.name ?? null;
    };

    // This device\'s links - surfaced here so editing/removing a link is discoverable
    // without hunting for the edge on the map.
    const deviceLinks = (links ?? []).filter((l) => l.a_device_id === device.id || l.b_device_id === device.id);

    // Whether this device is placed on the currently-open map.
    const onThisMap = (mapDetail?.positions ?? []).some((p) => p.device_id === device.id);

    // Ping-only device: watched for up/down, never throughput-polled - so
    // no throughput service row, no interface list, no throughput chart (all honest
    // "nothing to show" rather than a perpetually-empty/errored section).
    const pingOnly = device.poll_method === 'none';

    // Services derived from existing signals (no probe subsystem in v1): ping = fping
    // status; the poll-method reachability follows whether util is flowing.
    const polled = ifaces.some((i) => i.util_in !== null || i.util_out !== null);
    const services: { label: string; state: DeviceStatus }[] = [
        { label: 'ICMP / ping', state: device.status },
        ...(pingOnly
            ? []
            : [
                  {
                      label: pollLabel[device.poll_method] ?? device.poll_method,
                      state: (device.status === 'down' ? 'down' : polled ? 'up' : 'unknown') as DeviceStatus,
                  },
              ]),
    ];

    return (
        <InspectorShell open={inspectorOpen}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    {isAdmin ? (
                        <ClassificationPicker device={device} />
                    ) : (
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md ring-1 ring-white/10">
                            <DeviceGlyph deviceId={device.id} vendor={device.vendor} model={device.model} type={device.device_type} icon={device.icon} iconColor={device.icon_color} className="h-6 w-6" />
                        </span>
                    )}
                    <div className="min-w-0">
                        <div className="truncate text-base font-bold tracking-tight text-white">{device.name}</div>
                        <div className="truncate text-[11px] text-white/40">
                            {device.model ?? device.vendor ?? device.mgmt_ip}
                        </div>
                    </div>
                </div>
                <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${badge.cls}`}>
                    <StatusDot status={device.status} /> {badge.label}
                </span>
            </div>

            <div className="grid grid-cols-3 gap-2">
                <a href={`winbox://${device.mgmt_ip}`} className={actionBtn}>
                    Winbox
                </a>
                <a href={`ssh://${device.mgmt_ip}`} className={actionBtn}>
                    <Terminal weight="light" className="h-3.5 w-3.5" /> SSH
                </a>
                {/* Path trace from the MyMate server to this device - read-only, so every operator gets it. */}
                <button
                    onClick={() => setTracing(true)}
                    title="Live hop-by-hop trace from this server to the device (MTR)"
                    className={actionBtn}
                >
                    <Path weight="light" className="h-3.5 w-3.5" /> Trace
                </button>
                {isAdmin && (
                    <button
                        onClick={doUpgrade}
                        disabled={!canUpgrade || upgrade.isPending}
                        title={
                            dev.poll_method !== 'routeros'
                                ? 'RouterOS devices only'
                                : upgrading
                                  ? dev.upgrade_message ?? 'Upgrade in progress'
                                  : 'Download latest RouterOS + reboot'
                        }
                        className={`${actionBtn} ${dev.poll_method !== 'routeros' ? 'cursor-not-allowed opacity-40' : ''}`}
                    >
                        {upgrading || upgrade.isPending ? (
                            <>
                                <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" /> Upgrading...
                            </>
                        ) : (
                            'Upgrade'
                        )}
                    </button>
                )}
            </div>

            {isAdmin && (
                <button onClick={() => setEditingDevice(true)} className={`${actionBtn} w-full justify-center`}>
                    <PencilSimple weight="bold" className="h-3.5 w-3.5" /> Edit device options
                </button>
            )}

            {/* Firmware version / live upgrade state. */}
            {dev.poll_method === 'routeros' && (dev.os_version || dev.upgrade_status) && (
                <div className="-mt-2 flex items-center justify-between px-0.5">
                    <span className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">Firmware</span>
                    <UpgradeStatusBadge device={dev} />
                </div>
            )}

            <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                <Detail label="Mgmt IP" value={device.mgmt_ip} mono />
                <Detail label="Uptime" value={fmtUptime(device.uptime_seconds, device.uptime_at)} mono />
                {isAdmin ? (
                    <PollMethodPicker device={device} />
                ) : (
                    <Detail label="Poll method" value={pollLabel[device.poll_method] ?? device.poll_method} />
                )}
                {isAdmin && !pingOnly && <CredentialPicker device={device} />}
                <Detail label="Parent" value={device.parent_name ?? '-'} />
            </div>

            {(device.vendor || device.model || device.serial || device.cpu || device.ram_bytes) && (
                <Section title="Hardware">
                    <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                        {device.vendor && <Detail label="Vendor" value={device.vendor} />}
                        {device.model && <Detail label="Model" value={device.model} />}
                        {device.cpu && <Detail label="CPU" value={device.cpu} />}
                        {device.ram_bytes ? <Detail label="RAM" value={fmtBytes(device.ram_bytes)} mono /> : null}
                        {device.serial && <Detail label="Serial" value={device.serial} mono />}
                        {device.os_version && <Detail label="Firmware" value={device.os_version} mono />}
                    </div>
                </Section>
            )}

            {!pingOnly && (
            <Section title="Total throughput" right={totalBps > 0 ? formatRate(totalBps) : undefined}>
                <div className="group relative">
                    <button
                        type="button"
                        onClick={() => setChartExpanded(true)}
                        title="Expand - larger view + time range"
                        className="absolute right-0 top-0 z-10 rounded-lg p-1 text-white/35 opacity-0 transition-opacity duration-200 hover:bg-white/5 hover:text-white/80 group-hover:opacity-100"
                    >
                        <ArrowsOut weight="bold" className="h-3.5 w-3.5" />
                    </button>
                    <InterfaceChart
                        samples={samples}
                        loading={samplesLoading}
                        mode={chartMode}
                        onModeChange={(m) => setDeviceChartMode(device.id, m)}
                        hasSpeed={allHaveSpeed}
                    />
                </div>
            </Section>
            )}

            <Section title="Health">
                <DeviceResources device={device} />
            </Section>

            {pingOnly ? (
                <Section title="Interfaces">
                    <Empty>Ping-only device - monitored for up/down only. No interfaces or throughput are polled (change its poll method to SNMP or RouterOS to collect them).</Empty>
                </Section>
            ) : (
                <Section title={`Interfaces - ${ifaces.length}`} right={ifaces.length && isAdmin ? 'capacity ✎' : undefined}>
                    {ifaces.length === 0 ? (
                        <div className="space-y-2">
                            <Empty>
                                {device.discovery_error ?? 'No interfaces discovered yet - they appear after a poll.'}
                            </Empty>
                            {isAdmin && (
                                <button
                                    onClick={() => discover.mutate({ id: device.id, name: device.name })}
                                    disabled={discover.isPending}
                                    className={`${actionBtn} w-full justify-center`}
                                >
                                    <MagnifyingGlass weight="light" className="h-3.5 w-3.5" />
                                    {discover.isPending ? 'Discovering...' : 'Discover interfaces'}
                                </button>
                            )}
                        </div>
                    ) : (
                        <InterfacesList deviceId={device.id} ifaces={ifaces} peerName={peerName} />
                    )}
                </Section>
            )}

            <Section title={`Links - ${deviceLinks.length}`}>
                {deviceLinks.length === 0 ? (
                    <Empty>No links yet - drag this device onto another on the map, or use Add link below to reach a device on any map.</Empty>
                ) : (
                    <div className="space-y-1.5">
                        {deviceLinks.map((l) => {
                            const mine = l.a_device_id === device.id;
                            const localIf = mine ? l.a_interface : l.b_interface;
                            const peerIf = mine ? l.b_interface : l.a_interface;
                            const peerDevId = mine ? l.b_device_id : l.a_device_id;
                            const peerNm = devices?.find((d) => d.id === peerDevId)?.name ?? `device ${peerDevId}`;
                            return (
                                <div key={l.id} className="flex items-center gap-1.5 text-xs">
                                    <span className="min-w-0 flex-1 truncate text-white/80">
                                        {localIf?.name ?? '(no interface)'}
                                        <span className="text-white/35"> → {peerNm}</span>
                                        {peerIf && <span className="text-white/30"> - {peerIf.name}</span>}
                                    </span>
                                    {isAdmin && (
                                        <>
                                            <button
                                                onClick={() => setEditingLink(l)}
                                                title="Edit link (re-bind an interface)"
                                                className="rounded-md p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80"
                                            >
                                                <PencilSimple weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                            <button
                                                onClick={() => setDeletingLink({ id: l.id, label: `${localIf?.name ?? '(no interface)'} -> ${peerNm}` })}
                                                title="Delete link"
                                                className="rounded-md p-1 text-white/40 transition-colors hover:bg-rose-500/10 hover:text-rose-300"
                                            >
                                                <Trash weight="bold" className="h-3.5 w-3.5" />
                                            </button>
                                        </>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
                {isAdmin && (
                    <button onClick={() => setAddingLink(true)} className={`${actionBtn} mt-1 w-full justify-center`}>
                        <LinkSimple weight="bold" className="h-3.5 w-3.5" /> Add link
                    </button>
                )}
            </Section>

            {activeMapId !== null && isAdmin && (
                <Section title="Map">
                    {onThisMap ? (
                        <button
                            onClick={() => removeFromMap.mutate({ mapId: activeMapId, deviceId: device.id })}
                            className={`${actionBtn} w-full justify-center`}
                        >
                            Remove from this map
                        </button>
                    ) : (
                        <button
                            onClick={() => addToMap.mutate({ mapId: activeMapId, deviceId: device.id })}
                            className={`${actionBtn} w-full justify-center`}
                        >
                            Add to this map
                        </button>
                    )}
                </Section>
            )}

            <BackupSection device={device} isAdmin={isAdmin} />

            <Section title="Services">
                <div className="space-y-1.5">
                    {services.map((s) => (
                        <div key={s.label} className="flex items-center justify-between text-xs">
                            <span className="text-white/65">{s.label}</span>
                            <span className="flex items-center gap-1.5 text-white/45">
                                <StatusDot status={s.state} /> {s.state}
                            </span>
                        </div>
                    ))}
                </div>
            </Section>

            <Section title="Recent outages">
                {device.status === 'down' ? (
                    <Empty>Down since {relativeTime(device.last_change)}.</Empty>
                ) : (
                    <Empty>
                        {device.last_change
                            ? `Last status change ${relativeTime(device.last_change)}. Full outage history arrives with the Outages log.`
                            : 'No outage history yet - recorded events arrive with the Outages log.'}
                    </Empty>
                )}
            </Section>

            {/* Edit a link straight from the inspector (opens the existing dialog on its Edit tab). */}
            {editingLink && (
                <LinkHistoryDialog
                    link={editingLink}
                    devices={devices ?? []}
                    defaultTab="edit"
                    onClose={() => setEditingLink(null)}
                />
            )}

            {/* Add a link from this device to any other - including one on a different map. */}
            {addingLink && (
                <AddLinkDialog aDevice={device} devices={devices ?? []} onClose={() => setAddingLink(false)} />
            )}

            {/* Edit this device's options (name, IP, poll method, credential, type, monitoring). */}
            {editingDevice && (
                <DeviceDialog mode="edit" device={device} onClose={() => setEditingDevice(false)} />
            )}

            {/* Enlarged, time-adjustable total-throughput chart. */}
            {chartExpanded && (
                <ChartModal
                    deviceId={device.id}
                    deviceName={device.name}
                    hasSpeed={allHaveSpeed}
                    onClose={() => setChartExpanded(false)}
                />
            )}

            {/* Live MTR trace to this device's mgmt IP; the run is stopped when this closes. */}
            {tracing && (
                <TraceModal
                    deviceId={device.id}
                    deviceName={device.name}
                    targetIp={device.mgmt_ip}
                    onClose={() => setTracing(false)}
                />
            )}

            {confirmingUpgrade && (
                <ConfirmDialog
                    title="Upgrade RouterOS"
                    message={
                        <>
                            Upgrade <span className="font-semibold text-white/85">{dev.name}</span>? It downloads the latest RouterOS and reboots the
                            device.
                        </>
                    }
                    confirmLabel="Upgrade"
                    busy={upgrade.isPending}
                    onConfirm={runUpgrade}
                    onClose={() => setConfirmingUpgrade(false)}
                />
            )}

            {deletingLink && (
                <ConfirmDialog
                    title="Delete link"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete the link <span className="font-semibold text-white/85">{deletingLink.label}</span>? The devices stay - only this
                            connection is removed.
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={delLink.isPending}
                    onConfirm={() =>
                        delLink.mutate(deletingLink.id, {
                            onSuccess: () => setDeletingLink(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t delete the link', tone: 'down' });
                                setDeletingLink(null);
                            },
                        })
                    }
                    onClose={() => setDeletingLink(null)}
                />
            )}
        </InspectorShell>
    );
}
