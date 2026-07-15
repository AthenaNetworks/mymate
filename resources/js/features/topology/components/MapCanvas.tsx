import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    ReactFlow,
    Background,
    BackgroundVariant,
    ConnectionMode,
    MiniMap,
    useNodesState,
    useEdgesState,
    useReactFlow,
    type Node,
    type Edge,
    type Connection,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { ArrowsOutCardinal, CaretDown, CircleDashed, DotsThreeVertical, Globe, Graph, Info, LineSegment, LinkBreak, Plus, Sparkle, TreeStructure, WaveSine } from '@phosphor-icons/react';
import { DeviceDialog, type DeviceDialogDefaults } from '../../devices/components/DeviceDialog';
import { DeviceNode } from '../nodes/DeviceNode';
import { MapPortalNode } from '../nodes/MapPortalNode';
import { UtilEdge, type UtilEdgeData } from '../edges/UtilEdge';
import { LinkBinderDialog, type PendingLink } from './LinkBinderDialog';
import { LinkHistoryDialog } from './LinkHistoryDialog';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { MapSearch } from './MapSearch';
import { MapControls } from './MapControls';
import { ConfirmDialog } from '../../../components/Dialog';
import { useMap, useSaveMapPosition, useSaveMapLinkPosition, useAddDeviceToMap } from '../../maps/api/maps';
import { useMapChannel } from '../hooks/useMapChannel';
import { useIsAdmin } from '../../auth/api/auth';
import { useDevices } from '../../devices/api/getDevices';
import { useLinks } from '../api/getLinks';
import { useDeleteLink } from '../api/deleteLink';
import { computeLayout, declump, type LayoutKind } from '../lib/layout';
import { selectDevice, setEdgeStyle, setInspectorOpen, setLayoutKind, useActiveMapId, useEdgeStyle, useLayoutKind, useSelectedDeviceId } from '../../../lib/shellStore';
import { pushToast } from '../../../lib/toast';
import type { Device, DeviceStatus, InterfaceUtilUpdatedPayload, Link } from '../../../types';

// Defined at module level so React Flow doesn\'t re-render the whole graph each render.
const nodeTypes = { device: DeviceNode, portal: MapPortalNode };
const edgeTypes = { util: UtilEdge };

const miniColor: Record<DeviceStatus, string> = {
    up: '#34d399',
    down: '#f43f5e',
    unknown: '#52525b',
};

// Auto-layout algorithms offered by the "Tidy ▾" menu. The LR tree
// reuses the TreeStructure glyph rotated 90 deg so it reads as a sideways hierarchy.
const LAYOUTS: { kind: LayoutKind; label: string; Icon: typeof TreeStructure; iconClass?: string }[] = [
    { kind: 'smart', label: 'Smart (recommended)', Icon: Sparkle },
    { kind: 'tree-tb', label: 'Vertical tree', Icon: TreeStructure },
    { kind: 'tree-lr', label: 'Horizontal tree', Icon: TreeStructure, iconClass: '-rotate-90' },
    { kind: 'radial', label: 'Radial', Icon: CircleDashed },
    { kind: 'force', label: 'Force-directed', Icon: Graph },
];

// Segmented-pill button for the curved/straight edge-style toggle.
const edgeBtn = (active: boolean): string =>
    `grid h-7 w-7 place-items-center rounded-full transition-colors duration-200 ease-fluid ${
        active ? 'bg-white/10 text-emerald-300 ring-1 ring-white/15' : 'text-white/45 hover:text-white/80'
    }`;

// Static per-edge identity the live recolour pass needs (kept in edge.data). Each link
// carries its effective speed per direction: the link override, else
// the slower of the two end interfaces - what util% is computed against.
type EdgeMeta = {
    aIf: number | null; // null = ping-only end (no interface, no throughput)
    bIf: number | null;
    aDev: number;
    bDev: number;
    effAb: number | null; // A->B effective speed (Mbps) - util_ab denominator
    effBa: number | null; // B->A effective speed (Mbps) - util_ba denominator
};
// Per-interface live signal: raw bps (the link util is derived from these) + per-port util%.
type UtilMap = Record<number, { in: number | null; out: number | null; bin: number | null; bout: number | null }>;

const metaOf = (l: Link): EdgeMeta => ({
    aIf: l.a_interface_id,
    bIf: l.b_interface_id,
    aDev: l.a_device_id,
    bDev: l.b_device_id,
    effAb: l.eff_ab_mbps,
    effBa: l.eff_ba_mbps,
});

const linkUtil = (l: Link): UtilMap => {
    const m: UtilMap = {};
    // Skip ping-only ends (no interface -> nothing to key or fold).
    if (l.a_interface_id !== null && l.a_interface) {
        m[l.a_interface_id] = { in: l.a_interface.util_in, out: l.a_interface.util_out, bin: l.a_interface.bps_in, bout: l.a_interface.bps_out };
    }
    if (l.b_interface_id !== null && l.b_interface) {
        m[l.b_interface_id] = { in: l.b_interface.util_in, out: l.b_interface.util_out, bin: l.b_interface.bps_in, bout: l.b_interface.bps_out };
    }
    return m;
};

// Max of a list ignoring null/undefined; null when there\'s nothing to compare.
const maxNum = (vals: Array<number | null | undefined>): number | null => {
    const nums = vals.filter((v): v is number => v != null);
    return nums.length ? Math.max(...nums) : null;
};

// The edge\'s colour/label inputs:
//  - util (colour): the busier of the two directions' utilisation = directional raw bps /
//    the link\'s effective speed for that direction; null when no direction has a known
//    speed, so the edge stays NEUTRAL (never ramped/green) per spec.
//  - mbps (label): the busiest directional throughput (shown always, even with no speed).
function computeData(meta: EdgeMeta, util: UtilMap, statusById: Record<number, DeviceStatus>): UtilEdgeData & EdgeMeta {
    const a = meta.aIf !== null ? util[meta.aIf] : undefined;
    const b = meta.bIf !== null ? util[meta.bIf] : undefined;

    // Directional throughput (bits/sec): A->B = traffic leaving A (a.bout) ~ arriving at B
    // (b.bin); B->A = b.bout ~ a.bin. Max to be robust to one end lacking a sample.
    const abBps = maxNum([a?.bout, b?.bin]);
    const baBps = maxNum([b?.bout, a?.bin]);

    const utilAb = abBps != null && meta.effAb ? (abBps / (meta.effAb * 1_000_000)) * 100 : null;
    const utilBa = baBps != null && meta.effBa ? (baBps / (meta.effBa * 1_000_000)) * 100 : null;
    const max = maxNum([utilAb, utilBa]);

    const maxBps = maxNum([abBps, baBps]);
    const mbps = maxBps != null ? maxBps / 1_000_000 : null;

    const down = statusById[meta.aDev] === 'down' || statusById[meta.bDev] === 'down';

    return { ...meta, util: max, mbps, down };
}

export function MapCanvas() {
    const isAdmin = useIsAdmin();
    const { data: devices, isLoading } = useDevices();
    const { data: links } = useLinks();
    const activeMapId = useActiveMapId();
    const edgeStyle = useEdgeStyle(); // curved (default) / straight link geometry
    const layoutKind = useLayoutKind(); // last-applied auto-layout algorithm
    const selectedDeviceId = useSelectedDeviceId(); // focus its links, fade the rest
    const { data: mapDetail } = useMap(activeMapId); // membership + per-map positions + inter-map links
    const savePosition = useSaveMapPosition();
    const saveLinkPosition = useSaveMapLinkPosition();
    const deleteLink = useDeleteLink();
    const addToMap = useAddDeviceToMap();
    const { fitView, screenToFlowPosition } = useReactFlow();

    const [nodes, setNodes, onNodesChange] = useNodesState<Node>([]);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
    const [util, setUtil] = useState<UtilMap>({});
    const [deviceUtil, setDeviceUtil] = useState<Record<number, number | null>>({});
    const [deviceLoad, setDeviceLoad] = useState<Record<number, number | null>>({});
    // Add-device / add-internet-object dialog (null = closed). defaults preset the internet stub.
    const [deviceDialog, setDeviceDialog] = useState<{ defaults?: DeviceDialogDefaults } | null>(null);
    const [pending, setPending] = useState<PendingLink | null>(null);
    const [historyLinkId, setHistoryLinkId] = useState<number | null>(null);
    const [deleteLinkId, setDeleteLinkId] = useState<number | null>(null);
    const [layoutMenu, setLayoutMenu] = useState(false); // the "Tidy ▾" layout-algorithm dropdown
    const [toolsMenu, setToolsMenu] = useState(false); // mobile: all map tools behind one overflow button
    const [pendingLayout, setPendingLayout] = useState<LayoutKind | null>(null); // awaiting confirm before it overwrites positions

    // Stable so threading it into edge data doesn\'t churn the edge-build effect.
    const requestDelete = useCallback((linkId: number) => setDeleteLinkId(linkId), []);

    // This map\'s device placements + which links cross to another map.
    const posById = useMemo<Record<number, { x: number; y: number }>>(() => {
        const m: Record<number, { x: number; y: number }> = {};
        for (const p of mapDetail?.positions ?? []) m[p.device_id] = { x: p.x, y: p.y };
        return m;
    }, [mapDetail]);
    const memberSet = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);
    const interMapLinks = useMemo(() => mapDetail?.inter_map_links ?? [], [mapDetail]);
    const mapDevices = useMemo(() => (devices ?? []).filter((d) => memberSet.has(d.id)), [devices, memberSet]);
    const intraLinks = useMemo(
        () => (links ?? []).filter((l) => memberSet.has(l.a_device_id) && memberSet.has(l.b_device_id)),
        [links, memberSet],
    );

    // Live throughput -> util map (folded by useMapChannel from InterfaceUtilUpdated).
    const handleUtil = useCallback((payload: InterfaceUtilUpdatedPayload) => {
        setUtil((prev) => {
            const next = { ...prev };
            for (const dev of payload.devices) {
                for (const f of dev.interfaces) {
                    next[f.interface_id] = { in: f.util_in, out: f.util_out, bin: f.bps_in, bout: f.bps_out };
                }
            }
            return next;
        });
        setDeviceUtil((prev) => {
            const next = { ...prev };
            for (const dev of payload.devices) {
                let max: number | null = null;
                for (const f of dev.interfaces) {
                    for (const v of [f.util_in, f.util_out]) {
                        if (v !== null && (max === null || v > max)) max = v;
                    }
                }
                next[dev.device_id] = max;
            }
            return next;
        });
        // Busiest absolute throughput (bps) per device - the LOAD tile falls back to this when
        // no interface speed is known, so util% is null but there's still real traffic to show.
        setDeviceLoad((prev) => {
            const next = { ...prev };
            for (const dev of payload.devices) {
                let max: number | null = null;
                for (const f of dev.interfaces) {
                    for (const v of [f.bps_in, f.bps_out]) {
                        if (v !== null && (max === null || v > max)) max = v;
                    }
                }
                next[dev.device_id] = max;
            }
            return next;
        });
    }, []);
    const handleStatus = useCallback((e: { name: string; status: DeviceStatus }) => {
        pushToast({
            title: `${e.name} is ${e.status}`,
            detail: e.status === 'down' ? 'No longer responding to ping' : e.status === 'up' ? 'Back online' : undefined,
            tone: e.status === 'up' ? 'up' : e.status === 'down' ? 'down' : 'info',
        });
    }, []);
    useMapChannel(handleUtil, handleStatus);

    const statusById = useMemo<Record<number, DeviceStatus>>(
        () => Object.fromEntries((devices ?? []).map((d) => [d.id, d.status])),
        [devices],
    );
    const statusRef = useRef(statusById);
    statusRef.current = statusById;
    const deviceUtilRef = useRef(deviceUtil);
    deviceUtilRef.current = deviceUtil;
    const deviceLoadRef = useRef(deviceLoad);
    deviceLoadRef.current = deviceLoad;
    // Current data read inside the (membership-keyed) rebuild effect, so it doesn't need to
    // list these as deps and re-run on every data refetch.
    const mapDevicesRef = useRef(mapDevices);
    mapDevicesRef.current = mapDevices;
    const interMapLinksRef = useRef(interMapLinks);
    interMapLinksRef.current = interMapLinks;
    const posByIdRef = useRef(posById);
    posByIdRef.current = posById;

    // A stable signature of *which* nodes are on the map and *where* - NOT their live data.
    // The full node rebuild keys off this so a metrics/status update (every ~30s) patches data
    // in place instead of replacing every node (which would drop the React Flow selection and
    // flicker the canvas).
    const membershipKey = useMemo(
        () =>
            mapDevices
                .map((d) => {
                    const p = posById[d.id] ?? { x: d.map_x, y: d.map_y };
                    return `${d.id}@${Math.round(p.x)},${Math.round(p.y)}`;
                })
                .join('|') +
            '#' +
            interMapLinks.map((il) => `${il.id}@${il.portal_x ?? ''},${il.portal_y ?? ''}`).join('|'),
        [mapDevices, posById, interMapLinks],
    );

    // Map devices -> nodes, positioned per-map; plus a portal node per inter-map link. Rebuilds
    // only when the membership/positions change (membershipKey), reading current data from refs.
    useEffect(() => {
        const deviceNodes: Node[] = mapDevicesRef.current.map((d) => ({
            id: String(d.id),
            type: 'device',
            position: posByIdRef.current[d.id] ?? { x: d.map_x, y: d.map_y },
            data: {
                label: d.name,
                mgmt_ip: d.mgmt_ip,
                status: d.status,
                device_type: d.device_type,
                vendor: d.vendor,
                model: d.model,
                util: deviceUtilRef.current[d.id] ?? null,
                load: deviceLoadRef.current[d.id] ?? null,
                cpu: d.cpu_pct,
                mem: d.mem_used_pct,
                temp: d.temp_c,
            },
        }));
        const perDevice: Record<number, number> = {};
        const portalNodes: Node[] = interMapLinksRef.current.map((il) => {
            const base = posByIdRef.current[il.local_device_id] ?? { x: 0, y: 0 };
            const i = (perDevice[il.local_device_id] = (perDevice[il.local_device_id] ?? 0) + 1);
            // Saved drag position if the operator moved it; else auto-place near its device.
            const position =
                il.portal_x != null && il.portal_y != null
                    ? { x: il.portal_x, y: il.portal_y }
                    : { x: base.x + 280, y: base.y + (i - 1) * 64 };
            return {
                id: `portal:${il.id}`,
                type: 'portal',
                position,
                data: {
                    deviceName: il.remote_device_name ?? 'device',
                    mapName: il.remote_map_name ?? 'other map',
                    mapId: il.remote_map_id,
                    bps: il.bps,
                    util: il.util,
                },
                draggable: isAdmin,
                selectable: true,
            };
        });
        setNodes([...deviceNodes, ...portalNodes]);
    }, [membershipKey, setNodes, isAdmin]);

    // Intra-map links -> util edges; inter-map links -> dashed portal edges. Seed util.
    useEffect(() => {
        const utilEdges: Edge[] = intraLinks.map((l) => ({
            id: String(l.id),
            source: String(l.a_device_id),
            target: String(l.b_device_id),
            type: 'util',
            data: { ...computeData(metaOf(l), linkUtil(l), statusRef.current), onRemove: isAdmin ? () => requestDelete(l.id) : undefined },
        }));
        const interEdges: Edge[] = interMapLinks.map((il) => ({
            id: `inter:${il.id}`,
            source: String(il.local_device_id),
            target: `portal:${il.id}`,
            animated: true,
            deletable: false,
            style: { stroke: 'rgba(129,140,248,0.55)', strokeDasharray: '6 4' },
        }));
        setEdges([...utilEdges, ...interEdges]);

        setUtil((prev) => {
            const base: UtilMap = {};
            for (const l of intraLinks) Object.assign(base, linkUtil(l));
            return { ...base, ...prev };
        });
        setDeviceUtil((prev) => {
            const base: Record<number, number | null> = {};
            for (const l of intraLinks) {
                for (const [dev, iface] of [
                    [l.a_device_id, l.a_interface],
                    [l.b_device_id, l.b_interface],
                ] as const) {
                    if (!iface) continue; // ping-only end - no interface util to seed
                    const m = Math.max(iface.util_in ?? -1, iface.util_out ?? -1);
                    if (m >= 0) base[dev] = base[dev] != null ? Math.max(base[dev]!, m) : m;
                }
            }
            return { ...base, ...prev };
        });
    }, [intraLinks, interMapLinks, setEdges, requestDelete, isAdmin]);

    // Recolour util edges in place when live util or device status changes.
    useEffect(() => {
        setEdges((eds) => eds.map((e) => (e.type === 'util' ? { ...e, data: { ...e.data, ...computeData(e.data as EdgeMeta, util, statusById) } } : e)));
    }, [util, statusById, setEdges]);

    // Patch each device node\'s busiest-util bar (and bps fallback) in place when live util changes.
    useEffect(() => {
        setNodes((nds) => nds.map((n) => (n.type === 'device' ? { ...n, data: { ...n.data, util: deviceUtil[Number(n.id)] ?? null, load: deviceLoad[Number(n.id)] ?? null } } : n)));
    }, [deviceUtil, deviceLoad, setNodes]);

    // Patch device data (status / metrics / name / model) in place when the devices query
    // updates - so a status or cpu/mem/temp change never rebuilds the node graph (which would
    // drop the selection and flicker). Membership changes are handled by the rebuild above.
    useEffect(() => {
        if (!devices) return;
        const byId = new Map(devices.map((d) => [d.id, d]));
        setNodes((nds) =>
            nds.map((n) => {
                if (n.type !== 'device') return n;
                const d = byId.get(Number(n.id));
                return d
                    ? { ...n, data: { ...n.data, label: d.name, status: d.status, device_type: d.device_type, vendor: d.vendor, model: d.model, cpu: d.cpu_pct, mem: d.mem_used_pct, temp: d.temp_c } }
                    : n;
            }),
        );
    }, [devices, setNodes]);

    // Selection focus: a selected device brings its own links forward and fades the rest.
    // Only touches the emphasized/dimmed flags (util fields are preserved by the spread), and
    // no-ops when nothing changed so it never fights the live-util updater above.
    useEffect(() => {
        setEdges((eds) =>
            eds.map((e) => {
                if (e.type !== 'util') return e;
                const connected = selectedDeviceId !== null && (Number(e.source) === selectedDeviceId || Number(e.target) === selectedDeviceId);
                const emphasized = connected;
                const dimmed = selectedDeviceId !== null && !connected;
                const cur = e.data as UtilEdgeData;
                if ((cur.emphasized ?? false) === emphasized && (cur.dimmed ?? false) === dimmed) return e;
                return { ...e, data: { ...cur, emphasized, dimmed } };
            }),
        );
    }, [selectedDeviceId, setEdges]);

    // Re-frame the viewport when the active map changes (once its devices have loaded).
    // Without this, switching maps keeps the previous map\'s pan/zoom and the new graph can
    // land off-screen / zoomed in. Keyed on `mapDevices` (which updates in the same render
    // as `activeMapId`), NOT the `nodes` state - that lags a render, so an earlier version
    // fitted the *previous* map\'s nodes and then skipped the real update. The ref fits each
    // map exactly once (no churn on live util ticks) and refits when you switch back later.
    const fittedMapRef = useRef<number | null>(null);
    useEffect(() => {
        if (activeMapId === null || mapDevices.length === 0) return;
        if (fittedMapRef.current === activeMapId) return;
        fittedMapRef.current = activeMapId;
        // Delay lets setNodes flush to React Flow\'s store (incl. node measurement) before framing.
        const t = setTimeout(() => fitView({ padding: 0.3, duration: 600 }), 140);
        return () => clearTimeout(t);
    }, [activeMapId, mapDevices, fitView]);

    // Apply a computed {id -> position} map to the canvas + persist each per-map, then frame.
    const applyPositions = useCallback(
        (pos: Record<number, { x: number; y: number }>) => {
            if (activeMapId === null) return;
            setNodes((nds) => nds.map((n) => (n.type === 'device' && pos[Number(n.id)] ? { ...n, position: pos[Number(n.id)] } : n)));
            for (const [id, p] of Object.entries(pos)) {
                savePosition.mutate({ mapId: activeMapId, deviceId: Number(id), x: p.x, y: p.y });
            }
            setTimeout(() => fitView({ padding: 0.3, duration: 600 }), 60);
        },
        [activeMapId, setNodes, savePosition, fitView],
    );

    // Snapshot the current on-canvas device positions (seeds the force layout + feeds Remove-overlaps).
    const currentPositions = useCallback(() => {
        const cur: Record<number, { x: number; y: number }> = {};
        for (const n of nodes) if (n.type === 'device') cur[Number(n.id)] = { x: n.position.x, y: n.position.y };
        return cur;
    }, [nodes]);

    // Auto-arrange is destructive + non-undoable: it overwrites every device\'s saved
    // position on this map. Confirm first so a stray click can\'t wipe a hand-arranged layout.
    const requestLayout = useCallback((kind: LayoutKind) => {
        setLayoutMenu(false);
        setPendingLayout(kind);
    }, []);

    // Apply the chosen algorithm (overlap-free), persist, then frame - runs after confirm.
    const runLayout = useCallback(
        (kind: LayoutKind) => {
            if (activeMapId === null) return;
            setPendingLayout(null);
            setLayoutKind(kind);
            applyPositions(computeLayout(kind, mapDevices, intraLinks, currentPositions()));
        },
        [activeMapId, mapDevices, intraLinks, applyPositions, currentPositions],
    );

    // Nudge only the *current* positions apart so no two cards overlap (no relayout).
    const removeOverlaps = useCallback(() => {
        if (activeMapId === null) return;
        setLayoutMenu(false);
        applyPositions(declump(currentPositions()));
    }, [activeMapId, applyPositions, currentPositions]);

    // Find-a-device search (React Flow has no built-in search - `fitView({ nodes })` is the
    // pan/zoom primitive). Selects it the same way a canvas click would (inspector + the
    // node\'s own selection ring), then frames just that node - capped zoom so a single card
    // doesn\'t fill the whole screen.
    const focusDevice = useCallback(
        (deviceId: number) => {
            const id = String(deviceId);
            selectDevice(deviceId);
            setInspectorOpen(true);
            setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, selected: true } : n.selected ? { ...n, selected: false } : n)));
            fitView({ nodes: [{ id }], duration: 700, padding: 0.6, maxZoom: 1.2 });
        },
        [setNodes, fitView],
    );

    if (isLoading) {
        return <div className="grid h-full place-items-center text-sm text-white/40">Loading map...</div>;
    }

    return (
        <>
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                // Hide the "React Flow" attribution watermark (permitted by the MIT-licensed lib).
                proOptions={{ hideAttribution: true }}
                // Loose: any handle is both in & out - direction doesn\'t matter (floating
                // edges compute geometry from the cards, so a link\'s a/b end is cosmetic).
                connectionMode={ConnectionMode.Loose}
                nodesDraggable={isAdmin}
                nodesConnectable={isAdmin}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onNodeDragStop={(_, node) => {
                    if (!isAdmin || activeMapId === null) return;
                    // Inter-map link portals are draggable too - persist their position.
                    if (node.type === 'portal') {
                        const linkId = Number(node.id.replace('portal:', ''));
                        if (linkId) saveLinkPosition.mutate({ mapId: activeMapId, linkId, x: node.position.x, y: node.position.y });
                        return;
                    }
                    if (node.type !== 'device') return;
                    savePosition.mutate({ mapId: activeMapId, deviceId: Number(node.id), x: node.position.x, y: node.position.y });
                }}
                onConnect={(c: Connection) => {
                    if (!isAdmin) return;
                    if (c.source && c.target && c.source !== c.target && !c.source.startsWith('portal:') && !c.target.startsWith('portal:')) {
                        setPending({ aDeviceId: Number(c.source), bDeviceId: Number(c.target) });
                    }
                }}
                onEdgesDelete={(deleted) => isAdmin && deleted.forEach((e) => e.type === 'util' && deleteLink.mutate(Number(e.id)))}
                onEdgeClick={(_, edge) => edge.type === 'util' && setHistoryLinkId(Number(edge.id))}
                onNodeClick={(_, node) => {
                    if (node.type === 'device') {
                        selectDevice(Number(node.id));
                        setInspectorOpen(true); // surface the inspector sheet on phones/tablets
                    }
                }}
                // Click the empty canvas to deselect - the inspector then shows the map tools.
                onPaneClick={() => selectDevice(null)}
                // Drag a device from the palette (inspector) onto the map to place it there.
                onDragOver={(e) => {
                    if (e.dataTransfer.types.includes('application/mymate-device')) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                    }
                }}
                onDrop={(e) => {
                    e.preventDefault();
                    if (!isAdmin || activeMapId === null) return;
                    const deviceId = Number(e.dataTransfer.getData('application/mymate-device'));
                    if (!deviceId) return;
                    const pos = screenToFlowPosition({ x: e.clientX, y: e.clientY });
                    addToMap.mutate(
                        { mapId: activeMapId, deviceId },
                        { onSuccess: () => { savePosition.mutate({ mapId: activeMapId, deviceId, x: pos.x, y: pos.y }); selectDevice(deviceId); } },
                    );
                }}
                fitView
                fitViewOptions={{ padding: 0.3 }}
                // React Flow's default minZoom is 0.5 - far too tight to fit a large map (dozens of
                // 200px nodes), so fitView would clamp and land zoomed into a corner, especially on
                // a phone. Allow it to zoom right out so the whole topology fits on any screen.
                minZoom={0.1}
                snapToGrid
                snapGrid={[16, 16]}
                colorMode="dark"
            >
                {/* Layered "blueprint" grid - a coarse major grid over a fine minor grid, both
                    very faint. Reads as an instrument/graph-paper backdrop rather than the stock
                    React Flow dot field, and gives the canvas depth against the mesh glow. */}
                <Background id="major" variant={BackgroundVariant.Lines} gap={128} lineWidth={1} color="rgba(255,255,255,0.028)" />
                <Background id="minor" variant={BackgroundVariant.Dots} gap={32} size={1} color="rgba(255,255,255,0.05)" />
                <MapControls />
                <MiniMap
                    pannable
                    zoomable
                    className="!hidden !rounded-xl !bg-white/[0.03] !ring-1 !ring-white/10 lg:!block"
                    maskColor="rgba(0,0,0,0.6)"
                    nodeColor={(n) => miniColor[(n.data as { status?: DeviceStatus }).status ?? 'unknown'] ?? miniColor.unknown}
                />
            </ReactFlow>

            {/* Toolbar - one flex row so the two clusters wrap onto a second line instead of
                overlapping when they don\'t both fit (e.g. the search pill expanded on focus).
                Two independently `absolute`-positioned corners used to paint over each other
                here since neither knew the other\'s width. */}
            <div className="absolute inset-x-4 top-4 z-10 flex flex-wrap items-start gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <MapSwitcher />
                    <span className="pointer-events-none hidden rounded-full bg-[#0d0d11]/80 px-3 py-1.5 font-mono text-[11px] tabular-nums text-white/50 ring-1 ring-white/10 backdrop-blur-xl sm:inline-block">
                        {mapDevices.length} nodes - {intraLinks.length} links
                    </span>
                    <MapSearch devices={mapDevices} onSelect={focusDevice} />
                </div>
                {/* ml-auto keeps this cluster right-aligned whether it shares the first line
                    or wraps to its own - plain justify-between would collapse a lone wrapped
                    item to the left edge instead. */}
                <div className="ml-auto flex items-center gap-2">
                    {/* Desktop / tablet: the tool clusters inline. Below lg they collapse into the
                        single overflow menu below so the toolbar never wraps over the map. */}
                    <div className="hidden items-center gap-2 lg:flex">
                    {/* Curved / straight link geometry. */}
                    <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10 backdrop-blur-xl">
                        <button onClick={() => setEdgeStyle('curved')} title="Curved links" className={edgeBtn(edgeStyle === 'curved')}>
                            <WaveSine weight="bold" className="h-4 w-4" />
                        </button>
                        <button onClick={() => setEdgeStyle('straight')} title="Straight links" className={edgeBtn(edgeStyle === 'straight')}>
                            <LineSegment weight="bold" className="h-4 w-4" />
                        </button>
                    </div>
                    {isAdmin && (
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10 backdrop-blur-xl">
                            <button
                                onClick={() => setDeviceDialog({})}
                                title="Add a device to this map"
                                className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-white/75 transition-colors duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                            >
                                <Plus weight="bold" className="h-4 w-4 text-emerald-300" />
                                <span className="hidden sm:inline">Add device</span>
                            </button>
                            <button
                                onClick={() => setDeviceDialog({ defaults: { name: 'Internet', mgmt_ip: '1.1.1.1', device_type: 'internet', poll_method: 'none' } })}
                                title="Add a generic internet / upstream object - link a device's uplink interface back to it"
                                className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-white/75 transition-colors duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                            >
                                <Globe weight="light" className="h-4 w-4 text-sky-300" />
                                <span className="hidden md:inline">Internet</span>
                            </button>
                        </div>
                    )}
                    {isAdmin && (
                    <div className="relative">
                        <button
                            onClick={() => setLayoutMenu((o) => !o)}
                            className="group flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 backdrop-blur-xl transition-all duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                        >
                            <TreeStructure weight="light" className="h-4 w-4 text-emerald-300" />
                            <span className="hidden sm:inline">Tidy layout</span>
                            <CaretDown weight="bold" className={`h-3 w-3 transition-transform duration-300 ease-fluid ${layoutMenu ? 'rotate-180' : ''}`} />
                        </button>
                        {layoutMenu && (
                            <>
                                {/* Click-away - closes the menu on any outside click. */}
                                <div className="fixed inset-0 z-10" onClick={() => setLayoutMenu(false)} />
                                <div className="animate-rise absolute right-0 top-full z-20 mt-2 w-48 rounded-2xl bg-[#0d0d11]/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                                    <p className="px-2.5 pb-1 pt-1 text-[10px] uppercase tracking-wide text-white/30">Auto-layout</p>
                                    {LAYOUTS.map(({ kind, label, Icon, iconClass }) => (
                                        <button
                                            key={kind}
                                            onClick={() => requestLayout(kind)}
                                            className={`flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors duration-200 ease-fluid hover:bg-white/10 ${
                                                layoutKind === kind ? 'text-emerald-300' : 'text-white/75'
                                            }`}
                                        >
                                            <Icon weight="light" className={`h-4 w-4 ${iconClass ?? ''}`} />
                                            <span className="flex-1">{label}</span>
                                            {layoutKind === kind && <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />}
                                        </button>
                                    ))}
                                    <div className="my-1 h-px bg-white/10" />
                                    <button
                                        onClick={removeOverlaps}
                                        className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/75 transition-colors duration-200 ease-fluid hover:bg-white/10"
                                    >
                                        <ArrowsOutCardinal weight="light" className="h-4 w-4" />
                                        <span className="flex-1">Remove overlaps</span>
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                    )}
                    </div>

                    {/* Below lg: one overflow button holding every map tool, so the toolbar stays a
                        single row on a phone instead of wrapping across the top of the map. */}
                    <div className="relative lg:hidden">
                        <button
                            onClick={() => setToolsMenu((o) => !o)}
                            title="Map tools"
                            className="flex items-center justify-center rounded-full bg-white/5 p-2 text-white/75 ring-1 ring-white/10 backdrop-blur-xl transition-colors hover:bg-white/10 hover:text-white active:scale-[0.98]"
                        >
                            <DotsThreeVertical weight="bold" className="h-4 w-4" />
                        </button>
                        {toolsMenu && (
                            <>
                                <div className="fixed inset-0 z-10" onClick={() => setToolsMenu(false)} />
                                <div className="animate-rise absolute right-0 top-full z-20 mt-2 w-52 rounded-2xl bg-[#0d0d11]/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                                    <p className="px-2.5 pb-1 pt-1 text-[10px] uppercase tracking-wide text-white/30">Links</p>
                                    <div className="flex gap-1 px-1 pb-1.5">
                                        <button onClick={() => { setEdgeStyle('curved'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeStyle === 'curved' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <WaveSine weight="bold" className="h-4 w-4" /> Curved
                                        </button>
                                        <button onClick={() => { setEdgeStyle('straight'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeStyle === 'straight' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <LineSegment weight="bold" className="h-4 w-4" /> Straight
                                        </button>
                                    </div>
                                    {isAdmin && (
                                        <>
                                            <div className="my-1 h-px bg-white/10" />
                                            <button onClick={() => { setDeviceDialog({}); setToolsMenu(false); }} className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/80 transition-colors hover:bg-white/10">
                                                <Plus weight="bold" className="h-4 w-4 text-emerald-300" /> Add device
                                            </button>
                                            <button onClick={() => { setDeviceDialog({ defaults: { name: 'Internet', mgmt_ip: '1.1.1.1', device_type: 'internet', poll_method: 'none' } }); setToolsMenu(false); }} className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/80 transition-colors hover:bg-white/10">
                                                <Globe weight="light" className="h-4 w-4 text-sky-300" /> Add internet object
                                            </button>
                                            <div className="my-1 h-px bg-white/10" />
                                            <p className="px-2.5 pb-1 pt-1 text-[10px] uppercase tracking-wide text-white/30">Auto-layout</p>
                                            {LAYOUTS.map(({ kind, label, Icon, iconClass }) => (
                                                <button key={kind} onClick={() => { requestLayout(kind); setToolsMenu(false); }} className={`flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors hover:bg-white/10 ${layoutKind === kind ? 'text-emerald-300' : 'text-white/75'}`}>
                                                    <Icon weight="light" className={`h-4 w-4 ${iconClass ?? ''}`} />
                                                    <span className="flex-1">{label}</span>
                                                </button>
                                            ))}
                                            <button onClick={() => { removeOverlaps(); setToolsMenu(false); }} className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/75 transition-colors hover:bg-white/10">
                                                <ArrowsOutCardinal weight="light" className="h-4 w-4" /> Remove overlaps
                                            </button>
                                        </>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            {/* Open the inspector sheet on phones/tablets (it\'s off-canvas there). At lg+ the
                inspector is a permanent column, so this is hidden. */}
            <button
                onClick={() => setInspectorOpen(true)}
                className="absolute bottom-4 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2 rounded-full bg-white/5 px-4 py-2 text-xs font-medium text-white/80 ring-1 ring-white/10 backdrop-blur-xl transition-all duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98] lg:hidden"
            >
                <Info weight="light" className="h-4 w-4 text-emerald-300" />
                Details
            </button>

            {!isLoading && mapDevices.length === 0 && (
                <div className="pointer-events-none absolute inset-0 grid place-items-center">
                    <div className="max-w-xs text-center">
                        <p className="text-sm font-medium text-white/70">No devices on this map yet</p>
                        <p className="mt-1 text-xs text-white/40">
                            Add a device, or place existing ones here from the device inspector.
                        </p>
                    </div>
                </div>
            )}

            {pending && devices && <LinkBinderDialog pending={pending} devices={devices} onClose={() => setPending(null)} />}

            {/* Add a device (or a generic internet object) straight onto this map: create it, then
                drop it at the current viewport centre and select it. */}
            {deviceDialog && (
                <DeviceDialog
                    mode="create"
                    defaults={deviceDialog.defaults}
                    onClose={() => setDeviceDialog(null)}
                    onCreated={(d: Device) => {
                        if (activeMapId === null) return;
                        const pos = screenToFlowPosition({ x: window.innerWidth / 2, y: window.innerHeight / 2 });
                        addToMap.mutate(
                            { mapId: activeMapId, deviceId: d.id },
                            { onSuccess: () => { savePosition.mutate({ mapId: activeMapId, deviceId: d.id, x: pos.x, y: pos.y }); selectDevice(d.id); } },
                        );
                    }}
                />
            )}

            {/* Single-click an edge -> history + an Edit tab to re-bind either end. */}
            {historyLinkId !== null && devices && links?.some((l) => l.id === historyLinkId) && (
                <LinkHistoryDialog link={links.find((l) => l.id === historyLinkId)!} devices={devices} onClose={() => setHistoryLinkId(null)} />
            )}

            {/* ✕ on an edge -> confirm, then remove the link. */}
            {deleteLinkId !== null &&
                (() => {
                    const l = (links ?? []).find((x) => x.id === deleteLinkId);
                    const aN = devices?.find((d) => d.id === l?.a_device_id)?.name ?? 'a device';
                    const bN = devices?.find((d) => d.id === l?.b_device_id)?.name ?? 'another';
                    return (
                        <ConfirmDialog
                            title="Remove link"
                            icon={<LinkBreak weight="light" className="h-5 w-5" />}
                            message={
                                <>
                                    Remove the link between <span className="font-semibold text-white/85">{aN}</span> and{' '}
                                    <span className="font-semibold text-white/85">{bN}</span>? The devices stay - only this connection is removed.
                                </>
                            }
                            confirmLabel="Remove link"
                            tone="danger"
                            busy={deleteLink.isPending}
                            onConfirm={() =>
                                deleteLink.mutate(deleteLinkId, {
                                    onSuccess: () => setDeleteLinkId(null),
                                    onError: () => {
                                        pushToast({ title: 'Couldn\'t remove the link', tone: 'down' });
                                        setDeleteLinkId(null);
                                    },
                                })
                            }
                            onClose={() => setDeleteLinkId(null)}
                        />
                    );
                })()}

            {/* Auto-layout -> confirm before overwriting every saved position on this map. */}
            {pendingLayout !== null && (
                <ConfirmDialog
                    title="Auto-arrange this map"
                    icon={<TreeStructure weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            The <span className="font-semibold text-white/85">{LAYOUTS.find((l) => l.kind === pendingLayout)?.label ?? 'chosen'}</span>{' '}
                            layout will reposition every device on this map and overwrite their saved locations. This can\'t be undone. Continue?
                        </>
                    }
                    confirmLabel="Arrange"
                    onConfirm={() => runLayout(pendingLayout)}
                    onClose={() => setPendingLayout(null)}
                />
            )}
        </>
    );
}
