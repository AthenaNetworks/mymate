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
import { ArrowCounterClockwise, ArrowsOutCardinal, CaretDown, CircleDashed, DotsThreeVertical, Globe, Graph, Info, LineSegment, LinkBreak, MagnetStraight, Note, Plus, PushPin, Sparkle, TreeStructure, WaveSine } from '@phosphor-icons/react';
import { DeviceDialog, type DeviceDialogDefaults } from '../../devices/components/DeviceDialog';
import { useTheme } from '../../../lib/theme';
import { DeviceNode } from '../nodes/DeviceNode';
import { MapPortalNode } from '../nodes/MapPortalNode';
import { ChildMapNode } from '../nodes/ChildMapNode';
import { MapNoteNode } from '../nodes/MapNoteNode';
import { UtilEdge, type UtilEdgeData } from '../edges/UtilEdge';
import { MapLinkEdge } from '../edges/MapLinkEdge';
import { ChildLinkEdge } from '../edges/ChildLinkEdge';
import { LinkBinderDialog, type PendingLink } from './LinkBinderDialog';
import { LinkHistoryDialog } from './LinkHistoryDialog';
import { AddChildMapDialog } from './AddChildMapDialog';
import { MapLinkEditor } from './MapLinkEditor';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { MapBreadcrumb } from '../../maps/components/MapBreadcrumb';
import { MapSearch } from './MapSearch';
import { MapControls } from './MapControls';
import { OspfCostControl } from './OspfCostControl';
import { ConfirmDialog } from '../../../components/Dialog';
import { useMap, useSaveMapPosition, useSaveMapLinkPosition, useAddDeviceToMap, useSaveChildMapPosition, useCreateMapLink, useUpdateMapLink, useDeleteMapLink, useRemoveChildMap, useCreateMapNote, useUpdateMapNote, useDeleteMapNote } from '../../maps/api/maps';
import { useMapChannel } from '../hooks/useMapChannel';
import { useIsAdmin } from '../../auth/api/auth';
import { useDevices } from '../../devices/api/getDevices';
import { useLinks } from '../api/getLinks';
import { useDeleteLink } from '../api/deleteLink';
import { useUpdateLink } from '../api/updateLink';
import { computeLayout, dependencyLayout, declump, type LayoutKind } from '../lib/layout';
import { useCaptureLayoutSnapshot, useUndoLayout, useLayoutSnapshotCount } from '../api/layoutSnapshots';
import { computeData, linkUtil, metaOf, type EdgeMeta, type UtilMap } from '../lib/edgeData';
import { selectDevice, setEdgeStyle, setEdgeAttach, setInspectorOpen, setLayoutKind, useActiveMapId, useEdgeStyle, useEdgeAttach, useLayoutKind, useSelectedDeviceId } from '../../../lib/shellStore';
import { pushToast } from '../../../lib/toast';
import type { Device, DeviceStatus, InterfaceUtilUpdatedPayload } from '../../../types';

// Defined at module level so React Flow doesn\'t re-render the whole graph each render.
const nodeTypes = { device: DeviceNode, portal: MapPortalNode, childmap: ChildMapNode, note: MapNoteNode };
const edgeTypes = { util: UtilEdge, mapLink: MapLinkEdge, childLink: ChildLinkEdge };

// Child-map nodes are keyed with this prefix so they never collide with device ids.
const CHILD_PREFIX = 'childmap:';
const childNodeId = (mapId: number) => `${CHILD_PREFIX}${mapId}`;
const childMapId = (nodeId: string) => Number(nodeId.slice(CHILD_PREFIX.length));
// Note nodes get their own prefix too.
const NOTE_PREFIX = 'note:';
const noteNodeId = (id: number) => `${NOTE_PREFIX}${id}`;
const noteId = (nodeId: string) => Number(nodeId.slice(NOTE_PREFIX.length));

const miniColor: Record<DeviceStatus, string> = {
    up: '#34d399',
    down: '#f43f5e',
    unknown: '#52525b',
};

// Auto-layout algorithms offered by the "Tidy ▾" menu. The LR tree
// reuses the TreeStructure glyph rotated 90 deg so it reads as a sideways hierarchy.
const LAYOUTS: { kind: LayoutKind; label: string; Icon: typeof TreeStructure; iconClass?: string }[] = [
    { kind: 'smart', label: 'Smart (recommended)', Icon: Sparkle },
    { kind: 'dependency', label: 'Dependency (from selected)', Icon: TreeStructure },
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

export function MapCanvas() {
    const isAdmin = useIsAdmin();
    const { data: devices, isLoading } = useDevices();
    const { data: links } = useLinks();
    const activeMapId = useActiveMapId();
    const edgeStyle = useEdgeStyle(); // curved (default) / straight link geometry
    const edgeAttach = useEdgeAttach(); // 'auto' floats links to the facing side; 'fixed' keeps pinned sides
    const layoutKind = useLayoutKind(); // last-applied auto-layout algorithm
    const selectedDeviceId = useSelectedDeviceId(); // focus its links, fade the rest
    const { data: mapDetail } = useMap(activeMapId); // membership + per-map positions + inter-map links
    const savePosition = useSaveMapPosition();
    const saveLinkPosition = useSaveMapLinkPosition();
    const saveChildPosition = useSaveChildMapPosition();
    const removeChildMap = useRemoveChildMap();
    const createMapLink = useCreateMapLink();
    const updateLink = useUpdateLink();
    const updateMapLink = useUpdateMapLink();
    const captureSnapshot = useCaptureLayoutSnapshot();
    const undoLayoutMut = useUndoLayout();
    const { data: snapshotCount = 0 } = useLayoutSnapshotCount(activeMapId);
    const deleteMapLink = useDeleteMapLink();
    const createMapNote = useCreateMapNote();
    const updateMapNote = useUpdateMapNote();
    const deleteMapNote = useDeleteMapNote();
    const deleteLink = useDeleteLink();
    const addToMap = useAddDeviceToMap();
    const { fitView, screenToFlowPosition } = useReactFlow();
    const theme = useTheme();

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
    const [addChildMap, setAddChildMap] = useState(false); // "Add map" dialog (place a child map)
    const [deleteMapLinkId, setDeleteMapLinkId] = useState<number | null>(null);
    const [editMapLinkId, setEditMapLinkId] = useState<number | null>(null);
    const [detachChildId, setDetachChildId] = useState<number | null>(null); // remove a child-map node from this canvas
    const [showChildLinks, setShowChildLinks] = useState(true); // toggle the aggregated device links between child maps (GitHub #9)
    const [layoutMenu, setLayoutMenu] = useState(false); // the "Tidy ▾" layout-algorithm dropdown
    const [toolsMenu, setToolsMenu] = useState(false); // mobile: all map tools behind one overflow button

    // Stable so threading it into edge data doesn\'t churn the edge-build effect.
    const requestDelete = useCallback((linkId: number) => setDeleteLinkId(linkId), []);
    const requestDetachChild = useCallback((childId: number) => setDetachChildId(childId), []);

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
    // Whether any link on this map carries an OSPF cost - gates the OSPF label control (GitHub #22).
    const hasOspfCost = useMemo(
        () => intraLinks.some((l) => l.a_interface?.ospf_cost != null || l.b_interface?.ospf_cost != null),
        [intraLinks],
    );
    // Child-map nodes placed on this canvas + the manual links between them (GitHub #9).
    const childMaps = useMemo(() => mapDetail?.child_maps ?? [], [mapDetail]);
    const mapLinks = useMemo(() => mapDetail?.map_links ?? [], [mapDetail]);
    const mapNotes = useMemo(() => mapDetail?.map_notes ?? [], [mapDetail]); // free-text annotations (GitHub #11)
    const childDeviceLinks = useMemo(() => mapDetail?.child_device_links ?? [], [mapDetail]); // real device links crossing child maps (GitHub #9)

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
    // Coalesce status-change toasts. A single flip still shows its own toast; a storm (large
    // fleet, or many devices flapping at once) collapses into one "N down, M back online"
    // summary per window instead of a firehose of individual toasts.
    const statusBuf = useRef<{ up: number; down: number; n: number; lastId: number; lastName: string; lastStatus: DeviceStatus }>(
        { up: 0, down: 0, n: 0, lastId: 0, lastName: '', lastStatus: 'unknown' },
    );
    const statusFlush = useRef<ReturnType<typeof setTimeout> | null>(null);
    const handleStatus = useCallback((e: { id: number; name: string; status: DeviceStatus }) => {
        const b = statusBuf.current;
        if (e.status === 'up') b.up++;
        else if (e.status === 'down') b.down++;
        b.n++;
        b.lastId = e.id;
        b.lastName = e.name;
        b.lastStatus = e.status;
        if (statusFlush.current) return; // a flush is already scheduled; keep accumulating
        statusFlush.current = setTimeout(() => {
            const s = statusBuf.current;
            // Status toasts always time out (the map dots and Alerts page carry the durable
            // record), and are keyed so a flapping device updates one toast instead of
            // stacking a duplicate every cycle.
            if (s.n === 1) {
                pushToast({
                    title: `${s.lastName} is ${s.lastStatus}`,
                    detail: s.lastStatus === 'down' ? 'No longer responding to ping' : s.lastStatus === 'up' ? 'Back online' : undefined,
                    tone: s.lastStatus === 'up' ? 'up' : s.lastStatus === 'down' ? 'down' : 'info',
                    key: `device-status-${s.lastId}`,
                }, 10000);
            } else {
                const parts: string[] = [];
                if (s.down) parts.push(`${s.down} down`);
                if (s.up) parts.push(`${s.up} back online`);
                pushToast({
                    title: parts.join(', '),
                    detail: `${s.n} devices changed state`,
                    tone: s.down >= s.up ? 'down' : 'up',
                    key: 'device-status-summary',
                }, 10000);
            }
            statusBuf.current = { up: 0, down: 0, n: 0, lastId: 0, lastName: '', lastStatus: 'unknown' };
            statusFlush.current = null;
        }, 3000);
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
    const childMapsRef = useRef(childMaps);
    childMapsRef.current = childMaps;
    const mapNotesRef = useRef(mapNotes);
    mapNotesRef.current = mapNotes;
    const activeMapIdRef = useRef(activeMapId);
    activeMapIdRef.current = activeMapId;

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
            interMapLinks.map((il) => `${il.id}@${il.portal_x ?? ''},${il.portal_y ?? ''}`).join('|') +
            '#' +
            childMaps.map((c) => `${c.id}@${Math.round(c.node_x ?? 0)},${Math.round(c.node_y ?? 0)}`).join('|') +
            '#' +
            mapNotes.map((n) => `${n.id}@${Math.round(n.x)},${Math.round(n.y)}:${n.color ?? ''}:${n.text}`).join('|'),
        [mapDevices, posById, interMapLinks, childMaps, mapNotes],
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
                icon: d.icon,
                icon_color: d.icon_color,
                vendor: d.vendor,
                model: d.model,
                util: deviceUtilRef.current[d.id] ?? null,
                load: deviceLoadRef.current[d.id] ?? null,
                cpu: d.cpu_pct,
                mem: d.mem_used_pct,
                temp: d.temp_c,
                rtt_ms: d.rtt_ms,
                loss_pct: d.loss_pct,
                latency_good_ms: d.latency_good_ms,
                latency_bad_ms: d.latency_bad_ms,
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
        // Child-map nodes placed on this overview canvas (GitHub #9).
        const childNodes: Node[] = childMapsRef.current.map((c, i) => ({
            id: childNodeId(c.id),
            type: 'childmap',
            position: { x: c.node_x ?? 40 + (i % 5) * 240, y: c.node_y ?? 40 + Math.floor(i / 5) * 140 },
            data: { mapId: c.id, name: c.name, deviceCount: c.device_count, onDetach: isAdmin ? () => requestDetachChild(c.id) : undefined },
            draggable: isAdmin,
            selectable: true,
        }));
        // Free-text notes / labels (GitHub #11).
        const noteNodes: Node[] = mapNotesRef.current.map((n) => ({
            id: noteNodeId(n.id),
            type: 'note',
            position: { x: n.x, y: n.y },
            data: {
                noteId: n.id, text: n.text, color: n.color, editable: isAdmin,
                onSave: isAdmin ? (text: string) => { const m = activeMapIdRef.current; if (m !== null) updateMapNote.mutate({ mapId: m, noteId: n.id, text }); } : undefined,
                onRemove: isAdmin ? () => { const m = activeMapIdRef.current; if (m !== null) deleteMapNote.mutate({ mapId: m, noteId: n.id }); } : undefined,
            },
            draggable: isAdmin,
            selectable: true,
        }));
        setNodes([...deviceNodes, ...portalNodes, ...childNodes, ...noteNodes]);
    }, [membershipKey, setNodes, isAdmin]);

    // Intra-map links -> util edges; inter-map links -> dashed portal edges. Seed util.
    useEffect(() => {
        const utilEdges: Edge[] = intraLinks.map((l) => ({
            id: String(l.id),
            source: String(l.a_device_id),
            target: String(l.b_device_id),
            // Bind to the exact sides the operator dragged (persisted per end). Without a
            // handle we leave it undefined so UtilEdge floats to the facing side as before.
            sourceHandle: l.a_handle ?? undefined,
            targetHandle: l.b_handle ?? undefined,
            type: 'util',
            data: { ...computeData(metaOf(l), linkUtil(l), statusRef.current), mediaType: l.media_type, onRemove: isAdmin ? () => requestDelete(l.id) : undefined },
        }));
        const interEdges: Edge[] = interMapLinks.map((il) => ({
            id: `inter:${il.id}`,
            source: String(il.local_device_id),
            target: `portal:${il.id}`,
            animated: true,
            deletable: false,
            reconnectable: false, // portals aren't operator-reconnectable
            style: { stroke: 'rgba(129,140,248,0.55)', strokeDasharray: '6 4' },
        }));
        // Manual device-less links between child-map nodes (GitHub #9), styled by medium.
        const mapLinkEdges: Edge[] = mapLinks.map((ml) => ({
            id: `maplink:${ml.id}`,
            source: childNodeId(ml.a_map_id),
            target: childNodeId(ml.b_map_id),
            sourceHandle: ml.a_handle ?? undefined,
            targetHandle: ml.b_handle ?? undefined,
            type: 'mapLink',
            data: { mediaType: ml.media_type, label: ml.label, onRemove: isAdmin ? () => setDeleteMapLinkId(ml.id) : undefined },
        }));
        // Aggregated real device links crossing between child maps (GitHub #9) - one per pair,
        // toggleable so an overview can show its actual wiring without a tangle.
        const childLinkEdges: Edge[] = showChildLinks
            ? childDeviceLinks.map((cl) => ({
                id: `childlink:${cl.a_map_id}-${cl.b_map_id}`,
                source: childNodeId(cl.a_map_id),
                target: childNodeId(cl.b_map_id),
                type: 'childLink',
                selectable: false,
                deletable: false,
                reconnectable: false,
                data: { count: cl.count },
            }))
            : [];
        setEdges([...utilEdges, ...interEdges, ...mapLinkEdges, ...childLinkEdges]);

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
    }, [intraLinks, interMapLinks, mapLinks, childDeviceLinks, showChildLinks, setEdges, requestDelete, isAdmin]);

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
                    ? { ...n, data: { ...n.data, label: d.name, status: d.status, device_type: d.device_type, icon: d.icon, icon_color: d.icon_color, vendor: d.vendor, model: d.model, cpu: d.cpu_pct, mem: d.mem_used_pct, temp: d.temp_c, rtt_ms: d.rtt_ms, loss_pct: d.loss_pct, latency_good_ms: d.latency_good_ms, latency_bad_ms: d.latency_bad_ms } }
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

    // Snapshot the current layout server-side first (so every tidy is undoable), then apply the
    // chosen algorithm (overlap-free), persist and frame. No confirm dialog - Undo is the safety net.
    const runLayout = useCallback(
        async (kind: LayoutKind) => {
            if (activeMapId === null) return;
            setLayoutMenu(false);
            setToolsMenu(false);
            setLayoutKind(kind);
            // Dependency tidy: rooted at the selected device (its downstream branch only, the root
            // left where it sits), else the whole map from the north-most device. Auto-attach so
            // the branch's links float to the facing sides as it fans south.
            if (kind === 'dependency') setEdgeAttach('auto');
            const positions =
                kind === 'dependency'
                    ? dependencyLayout(mapDevices, intraLinks, selectedDeviceId, currentPositions())
                    : computeLayout(kind, mapDevices, intraLinks, currentPositions());
            // Snapshot the CURRENT layout and wait for it to land BEFORE moving anything, so the
            // undo point is the real pre-tidy state - not a half-applied one (the two used to race).
            try {
                await captureSnapshot.mutateAsync({ mapId: activeMapId, note: LAYOUTS.find((l) => l.kind === kind)?.label ?? kind });
            } catch {
                // Snapshot failed - still apply, just without an undo point for this tidy.
            }
            applyPositions(positions);
        },
        [activeMapId, mapDevices, intraLinks, selectedDeviceId, applyPositions, currentPositions, captureSnapshot],
    );

    // Roll back the last tidy: restore the newest server-side snapshot's positions.
    const undoLayout = useCallback(() => {
        if (activeMapId === null || snapshotCount === 0) return;
        setLayoutMenu(false);
        setToolsMenu(false);
        undoLayoutMut.mutate(activeMapId, {
            onSuccess: (r) => applyPositions(r.positions),
            onError: () => pushToast({ title: 'Nothing to undo', tone: 'info' }),
        });
    }, [activeMapId, snapshotCount, undoLayoutMut, applyPositions]);

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
                    if (node.type === 'childmap') {
                        saveChildPosition.mutate({ mapId: activeMapId, childMapId: childMapId(node.id), x: node.position.x, y: node.position.y });
                        return;
                    }
                    if (node.type === 'note') {
                        updateMapNote.mutate({ mapId: activeMapId, noteId: noteId(node.id), x: node.position.x, y: node.position.y });
                        return;
                    }
                    if (node.type !== 'device') return;
                    savePosition.mutate({ mapId: activeMapId, deviceId: Number(node.id), x: node.position.x, y: node.position.y });
                }}
                onConnect={(c: Connection) => {
                    if (!isAdmin || !c.source || !c.target || c.source === c.target) return;
                    const bothChild = c.source.startsWith(CHILD_PREFIX) && c.target.startsWith(CHILD_PREFIX);
                    const bothDevice = !c.source.startsWith('portal:') && !c.target.startsWith('portal:') && !c.source.startsWith(CHILD_PREFIX) && !c.target.startsWith(CHILD_PREFIX);
                    if (bothChild && activeMapId !== null) {
                        // Manual overview link between two child-map nodes (GitHub #9).
                        createMapLink.mutate({
                            mapId: activeMapId,
                            a_map_id: childMapId(c.source), b_map_id: childMapId(c.target),
                            a_handle: c.sourceHandle ?? null, b_handle: c.targetHandle ?? null,
                        });
                    } else if (bothDevice) {
                        // Remember the exact handles dragged so the link attaches where the operator
                        // started/stopped, not the auto-floating side.
                        setPending({ aDeviceId: Number(c.source), bDeviceId: Number(c.target), aHandle: c.sourceHandle ?? null, bHandle: c.targetHandle ?? null });
                    }
                }}
                onReconnect={(oldEdge, conn) => {
                    // Drag a link's end onto a different side of a card to pin it there. Only a
                    // side change on the SAME two ends is a "move"; dropping onto another card is
                    // ignored (that would be a rebind). Pins are honoured when Auto-attach is off.
                    if (!isAdmin || !conn.source || !conn.target) return;
                    if (oldEdge.type === 'util') {
                        const l = intraLinks.find((x) => String(x.id) === String(oldEdge.id));
                        if (!l || String(l.a_device_id) !== conn.source || String(l.b_device_id) !== conn.target) return;
                        updateLink.mutate({
                            id: l.id,
                            a_device_id: l.a_device_id, a_interface_id: l.a_interface_id ?? null,
                            b_device_id: l.b_device_id, b_interface_id: l.b_interface_id ?? null,
                            bw_ab_mbps: l.bw_ab_mbps ?? null, bw_ba_mbps: l.bw_ba_mbps ?? null,
                            a_handle: conn.sourceHandle ?? null, b_handle: conn.targetHandle ?? null,
                        });
                    } else if (oldEdge.type === 'mapLink' && activeMapId !== null) {
                        const id = Number(String(oldEdge.id).replace('maplink:', ''));
                        const ml = mapLinks.find((x) => x.id === id);
                        if (!ml || childNodeId(ml.a_map_id) !== conn.source || childNodeId(ml.b_map_id) !== conn.target) return;
                        updateMapLink.mutate({ mapId: activeMapId, mapLinkId: id, a_handle: conn.sourceHandle ?? null, b_handle: conn.targetHandle ?? null });
                    }
                }}
                onEdgesDelete={(deleted) => isAdmin && deleted.forEach((e) => {
                    if (e.type === 'util') deleteLink.mutate(Number(e.id));
                    else if (e.type === 'mapLink' && activeMapId !== null) deleteMapLink.mutate({ mapId: activeMapId, mapLinkId: Number(String(e.id).replace('maplink:', '')) });
                })}
                onEdgeClick={(_, edge) => {
                    if (edge.type === 'util') setHistoryLinkId(Number(edge.id));
                    else if (edge.type === 'mapLink') setEditMapLinkId(Number(String(edge.id).replace('maplink:', '')));
                }}
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
                colorMode={theme === 'light' ? 'light' : 'dark'}
            >
                {/* Layered "blueprint" grid - a coarse major grid over a fine minor grid, both
                    very faint. Reads as an instrument/graph-paper backdrop rather than the stock
                    React Flow dot field, and gives the canvas depth against the mesh glow. */}
                <Background id="major" variant={BackgroundVariant.Lines} gap={128} lineWidth={1} color={theme === 'light' ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.028)'} />
                <Background id="minor" variant={BackgroundVariant.Dots} gap={32} size={1} color={theme === 'light' ? 'rgba(0,0,0,0.07)' : 'rgba(255,255,255,0.05)'} />
                <MapControls />
                <MiniMap
                    pannable
                    zoomable
                    className="!hidden !rounded-xl !bg-white/[0.03] !ring-1 !ring-white/10 lg:!block"
                    maskColor={theme === 'light' ? 'rgba(255,255,255,0.6)' : 'rgba(0,0,0,0.6)'}
                    nodeColor={(n) => miniColor[(n.data as { status?: DeviceStatus }).status ?? 'unknown'] ?? miniColor.unknown}
                />
            </ReactFlow>

            {/* Toolbar - one flex row so the two clusters wrap onto a second line instead of
                overlapping when they don\'t both fit (e.g. the search pill expanded on focus).
                Two independently `absolute`-positioned corners used to paint over each other
                here since neither knew the other\'s width. */}
            <div className="absolute inset-x-4 top-4 z-10 flex flex-wrap items-start gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <MapBreadcrumb />
                    <MapSwitcher />
                    <span className="pointer-events-none hidden rounded-full bg-surface/80 px-3 py-1.5 font-mono text-[11px] tabular-nums text-white/50 ring-1 ring-white/10 backdrop-blur-xl sm:inline-block">
                        {mapDevices.length} nodes - {intraLinks.length} links
                    </span>
                    {childDeviceLinks.length > 0 && (
                        <button
                            onClick={() => setShowChildLinks((v) => !v)}
                            title="Show the real device links that cross between the maps on this overview"
                            className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[11px] font-medium ring-1 backdrop-blur-xl transition-colors ${
                                showChildLinks
                                    ? 'bg-white/10 text-emerald-300 ring-white/15'
                                    : 'bg-surface/80 text-white/55 ring-white/10 hover:text-white/85'
                            }`}
                        >
                            <LineSegment weight="bold" className="h-3.5 w-3.5" />
                            <span className="hidden md:inline">Cross-map links</span>
                        </button>
                    )}
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
                    {/* Auto-attach: 'auto' floats each link end to the side facing the other card (tidies
                        as cards move); 'fixed' keeps ends where they were dragged, so you can pin them. */}
                    <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10 backdrop-blur-xl">
                        <button onClick={() => setEdgeAttach('auto')} title="Auto-attach links to the facing side" className={edgeBtn(edgeAttach === 'auto')}>
                            <MagnetStraight weight="bold" className="h-4 w-4" />
                        </button>
                        <button onClick={() => setEdgeAttach('fixed')} title="Keep links pinned to the side you dragged them to" className={edgeBtn(edgeAttach === 'fixed')}>
                            <PushPin weight="bold" className="h-4 w-4" />
                        </button>
                    </div>
                    {/* OSPF cost label size/colour - only when this map carries OSPF costs (GitHub #22). */}
                    {hasOspfCost && <OspfCostControl />}
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
                            <button
                                onClick={() => setAddChildMap(true)}
                                title="Place another map as a node on this overview, then link them"
                                className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-white/75 transition-colors duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                            >
                                <TreeStructure weight="light" className="h-4 w-4 text-indigo-300" />
                                <span className="hidden md:inline">Add map</span>
                            </button>
                            <button
                                onClick={() => {
                                    if (activeMapId === null) return;
                                    const pos = screenToFlowPosition({ x: window.innerWidth / 2, y: window.innerHeight / 2 });
                                    createMapNote.mutate({ mapId: activeMapId, text: 'New note', x: pos.x, y: pos.y });
                                }}
                                title="Add a free-text note / label to this map"
                                className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-white/75 transition-colors duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                            >
                                <Note weight="light" className="h-4 w-4 text-amber-300" />
                                <span className="hidden md:inline">Add note</span>
                            </button>
                        </div>
                    )}
                    {isAdmin && snapshotCount > 0 && (
                        <button
                            onClick={undoLayout}
                            disabled={undoLayoutMut.isPending}
                            title={`Undo the last tidy (${snapshotCount} step${snapshotCount === 1 ? '' : 's'} available)`}
                            className="group flex items-center gap-2 rounded-full bg-white/5 px-3 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 backdrop-blur-xl transition-all duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98] disabled:opacity-40"
                        >
                            <ArrowCounterClockwise weight="bold" className="h-4 w-4 text-emerald-300" />
                            <span className="hidden sm:inline">Undo tidy{snapshotCount > 1 ? ` (${snapshotCount})` : ''}</span>
                        </button>
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
                                <div className="animate-rise absolute right-0 top-full z-20 mt-2 w-48 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                                    <p className="px-2.5 pb-1 pt-1 text-[10px] uppercase tracking-wide text-white/30">Auto-layout</p>
                                    {LAYOUTS.map(({ kind, label, Icon, iconClass }) => (
                                        <button
                                            key={kind}
                                            onClick={() => runLayout(kind)}
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
                                <div className="animate-rise absolute right-0 top-full z-20 mt-2 w-52 rounded-2xl bg-surface/95 p-1.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                                    <p className="px-2.5 pb-1 pt-1 text-[10px] uppercase tracking-wide text-white/30">Links</p>
                                    <div className="flex gap-1 px-1 pb-1.5">
                                        <button onClick={() => { setEdgeStyle('curved'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeStyle === 'curved' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <WaveSine weight="bold" className="h-4 w-4" /> Curved
                                        </button>
                                        <button onClick={() => { setEdgeStyle('straight'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeStyle === 'straight' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <LineSegment weight="bold" className="h-4 w-4" /> Straight
                                        </button>
                                    </div>
                                    <div className="flex gap-1 px-1 pb-1.5">
                                        <button onClick={() => { setEdgeAttach('auto'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeAttach === 'auto' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <MagnetStraight weight="bold" className="h-4 w-4" /> Auto
                                        </button>
                                        <button onClick={() => { setEdgeAttach('fixed'); setToolsMenu(false); }} className={`flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs ${edgeAttach === 'fixed' ? 'bg-white/10 text-emerald-300' : 'text-white/70 hover:bg-white/5'}`}>
                                            <PushPin weight="bold" className="h-4 w-4" /> Pinned
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
                                                <button key={kind} onClick={() => runLayout(kind)} className={`flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs transition-colors hover:bg-white/10 ${layoutKind === kind ? 'text-emerald-300' : 'text-white/75'}`}>
                                                    <Icon weight="light" className={`h-4 w-4 ${iconClass ?? ''}`} />
                                                    <span className="flex-1">{label}</span>
                                                </button>
                                            ))}
                                            <button onClick={() => { removeOverlaps(); setToolsMenu(false); }} className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/75 transition-colors hover:bg-white/10">
                                                <ArrowsOutCardinal weight="light" className="h-4 w-4" /> Remove overlaps
                                            </button>
                                            {snapshotCount > 0 && (
                                                <button onClick={undoLayout} disabled={undoLayoutMut.isPending} className="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-1.5 text-left text-xs text-white/75 transition-colors hover:bg-white/10 disabled:opacity-40">
                                                    <ArrowCounterClockwise weight="bold" className="h-4 w-4 text-emerald-300" /> Undo tidy
                                                </button>
                                            )}
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

            {!isLoading && mapDevices.length === 0 && childMaps.length === 0 && mapNotes.length === 0 && (
                <div className="pointer-events-none absolute inset-0 grid place-items-center">
                    <div className="max-w-xs text-center">
                        <p className="text-sm font-medium text-white/70">Nothing on this map yet</p>
                        <p className="mt-1 text-xs text-white/40">
                            Add a device, place existing ones from the device inspector, or add a map for a top-level overview.
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

            {/* Place a map as a node on this overview (GitHub #9). */}
            {addChildMap && activeMapId !== null && (
                <AddChildMapDialog mapId={activeMapId} onClose={() => setAddChildMap(false)} />
            )}

            {/* Click a manual overview link -> set its medium + label. */}
            {editMapLinkId !== null && activeMapId !== null && mapLinks.some((ml) => ml.id === editMapLinkId) && (
                <MapLinkEditor mapId={activeMapId} link={mapLinks.find((ml) => ml.id === editMapLinkId)!} onClose={() => setEditMapLinkId(null)} />
            )}

            {/* Remove a child-map node from this canvas (the map + its devices stay). */}
            {detachChildId !== null && activeMapId !== null && (
                <ConfirmDialog
                    title="Remove map from overview"
                    icon={<LinkBreak weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Remove <span className="font-semibold text-white/85">{childMaps.find((c) => c.id === detachChildId)?.name ?? 'this map'}</span>{' '}
                            from this overview? The map and its devices stay - only its node and any links to it here are removed.
                        </>
                    }
                    confirmLabel="Remove"
                    tone="danger"
                    busy={removeChildMap.isPending}
                    onConfirm={() =>
                        removeChildMap.mutate(
                            { mapId: activeMapId, childMapId: detachChildId },
                            { onSuccess: () => setDetachChildId(null), onError: () => setDetachChildId(null) },
                        )
                    }
                    onClose={() => setDetachChildId(null)}
                />
            )}

            {/* ✕ on a manual map-link -> confirm, then remove it. */}
            {deleteMapLinkId !== null && activeMapId !== null && (
                <ConfirmDialog
                    title="Remove link"
                    icon={<LinkBreak weight="light" className="h-5 w-5" />}
                    message={<>Remove this overview link? The maps it connects stay - only the drawn link is removed.</>}
                    confirmLabel="Remove link"
                    tone="danger"
                    busy={deleteMapLink.isPending}
                    onConfirm={() =>
                        deleteMapLink.mutate(
                            { mapId: activeMapId, mapLinkId: deleteMapLinkId },
                            { onSuccess: () => setDeleteMapLinkId(null), onError: () => setDeleteMapLinkId(null) },
                        )
                    }
                    onClose={() => setDeleteMapLinkId(null)}
                />
            )}
        </>
    );
}
