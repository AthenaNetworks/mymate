import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ReactFlow, ReactFlowProvider, useReactFlow, useNodesState, useEdgesState, type Node, type Edge } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { MapPin } from '@phosphor-icons/react';
import { DeviceNode } from '../../topology/nodes/DeviceNode';
import { UtilEdge } from '../../topology/edges/UtilEdge';
import { GeoStackNode } from './GeoStackNode';
import { GeoTileLayer } from './GeoTileLayer';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { useDevices } from '../../devices/api/getDevices';
import { useLinks } from '../../topology/api/getLinks';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useMap } from '../../maps/api/maps';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { useMapConfig } from '../api/geo';
import { project, unproject, computeBaseZoom } from '../lib/mercator';
import { selectDevice, useActiveMapId, useSelectedDeviceId } from '../../../lib/shellStore';
import type { Device, DeviceStatus, Link } from '../../../types';

const nodeTypes = { device: DeviceNode, stack: GeoStackNode };
const edgeTypes = { util: UtilEdge };

const maxNum = (v: Array<number | null | undefined>): number | null => {
    const n = v.filter((x): x is number => x != null);
    return n.length ? Math.max(...n) : null;
};

/** UtilEdge data for a link (util%, load, down, capacity, medium) - lean version of MapCanvas's. */
function edgeData(l: Link, statusById: Record<number, DeviceStatus>) {
    const a = l.a_interface, b = l.b_interface;
    const abBps = maxNum([a?.bps_out, b?.bps_in]);
    const baBps = maxNum([b?.bps_out, a?.bps_in]);
    const utilAb = abBps != null && l.eff_ab_mbps ? (abBps / (l.eff_ab_mbps * 1e6)) * 100 : null;
    const utilBa = baBps != null && l.eff_ba_mbps ? (baBps / (l.eff_ba_mbps * 1e6)) * 100 : null;
    const maxBps = maxNum([abBps, baBps]);
    const down = statusById[l.a_device_id] === 'down' || statusById[l.b_device_id] === 'down';
    return { util: maxNum([utilAb, utilBa]), load: maxBps, mbps: maxBps != null ? maxBps / 1e6 : null, down, effAb: l.eff_ab_mbps, mediaType: l.media_type };
}

/** DeviceNode data from a device row (native card; live cpu/mem/status come from the query). */
function deviceData(d: Device) {
    return {
        label: d.name, mgmt_ip: d.mgmt_ip, status: d.status, device_type: d.device_type,
        icon: d.icon, icon_color: d.icon_color, vendor: d.vendor, model: d.model,
        util: null, load: null, cpu: d.cpu_pct, mem: d.mem_used_pct, temp: d.temp_c,
        rtt_ms: d.rtt_ms, loss_pct: d.loss_pct, latency_good_ms: d.latency_good_ms, latency_bad_ms: d.latency_bad_ms,
    };
}

const coordKey = (lat: number, lng: number) => `${lat.toFixed(5)},${lng.toFixed(5)}`;

function GeoFlowInner() {
    const activeMapId = useActiveMapId();
    const { data: config } = useMapConfig();
    const { data: mapDetail } = useMap(activeMapId);
    const { data: devices } = useDevices();
    const { data: links } = useLinks();
    const update = useUpdateDevice();
    const selectedDeviceId = useSelectedDeviceId();
    const { fitView } = useReactFlow();
    useMapChannel();

    const [nodes, setNodes, onNodesChange] = useNodesState<Node>([]);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const memberIds = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);
    const placed = useMemo(
        () => (devices ?? []).filter((d) => memberIds.has(d.id) && d.latitude != null && d.longitude != null),
        [devices, memberIds],
    );
    const intraLinks = useMemo(
        () => (links ?? []).filter((l) => memberIds.has(l.a_device_id) && memberIds.has(l.b_device_id)),
        [links, memberIds],
    );
    const statusById = useMemo(() => Object.fromEntries((devices ?? []).map((d) => [d.id, d.status])), [devices]);

    // Base zoom for the projection - stable while the *set* of placed devices is unchanged, so
    // dragging a pin (coords change, ids don't) never re-projects the whole map.
    const idsKey = useMemo(() => placed.map((d) => d.id).sort((a, b) => a - b).join(','), [placed]);
    const baseZoom = useMemo(
        () => computeBaseZoom(placed.map((d) => ({ lat: d.latitude as number, lng: d.longitude as number }))),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [idsKey],
    );

    const toggleStack = useCallback((key: string) => {
        setExpanded((prev) => { const n = new Set(prev); n.has(key) ? n.delete(key) : n.add(key); return n; });
    }, []);

    // Which node id each device currently lives under (its own, or a collapsed stack).
    const nodeIdOfDevice = useMemo(() => {
        const groups = new Map<string, Device[]>();
        for (const d of placed) {
            const k = coordKey(d.latitude as number, d.longitude as number);
            (groups.get(k) ?? groups.set(k, []).get(k)!).push(d);
        }
        const map = new Map<number, string>();
        for (const [k, g] of groups) {
            const collapsed = g.length > 1 && !expanded.has(k);
            for (const d of g) map.set(d.id, collapsed ? `stack:${k}` : String(d.id));
        }
        return { map, groups };
    }, [placed, expanded]);

    // Rebuild nodes when the placement/stacks change (positions from coords; drag is preserved by
    // React Flow between rebuilds). A rebuild signature keeps this off the every-tick path.
    const nodeSig = useMemo(
        () => placed.map((d) => `${d.id}:${d.latitude}:${d.longitude}:${d.status}`).join('|') + '#' + [...expanded].sort().join(',') + '#' + baseZoom.toFixed(3),
        [placed, expanded, baseZoom],
    );
    useEffect(() => {
        const built: Node[] = [];
        for (const [k, g] of nodeIdOfDevice.groups) {
            const [lat, lng] = [g[0].latitude as number, g[0].longitude as number];
            const at = project(lat, lng, baseZoom);
            if (g.length > 1 && !expanded.has(k)) {
                const down = g.filter((d) => d.status === 'down').length;
                built.push({ id: `stack:${k}`, type: 'stack', position: at, data: { count: g.length, down, names: g.map((d) => d.name), onToggle: () => toggleStack(k) }, draggable: false });
            } else if (g.length > 1) {
                // Fanned out around the point so each card is reachable.
                const r = 120;
                g.forEach((d, i) => {
                    const ang = (i / g.length) * Math.PI * 2;
                    built.push({ id: String(d.id), type: 'device', position: { x: at.x + r * Math.cos(ang), y: at.y + r * Math.sin(ang) }, data: deviceData(d), selected: d.id === selectedDeviceId });
                });
            } else {
                built.push({ id: String(g[0].id), type: 'device', position: at, data: deviceData(g[0]), selected: g[0].id === selectedDeviceId });
            }
        }
        setNodes(built);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nodeSig, setNodes]);

    // Live cpu/mem/temp/status patch without moving nodes.
    useEffect(() => {
        if (!devices) return;
        const byId = new Map(devices.map((d) => [d.id, d]));
        setNodes((nds) => nds.map((n) => (n.type === 'device' && byId.has(Number(n.id)) ? { ...n, data: { ...n.data, ...deviceData(byId.get(Number(n.id))!) } } : n)));
    }, [devices, setNodes]);

    // Edges: each end resolves to its device node, or the stack node while collapsed.
    useEffect(() => {
        const built: Edge[] = [];
        for (const l of intraLinks) {
            const s = nodeIdOfDevice.map.get(l.a_device_id);
            const t = nodeIdOfDevice.map.get(l.b_device_id);
            if (!s || !t || s === t) continue; // both inside the same collapsed stack -> hidden
            built.push({ id: String(l.id), source: s, target: t, type: 'util', data: edgeData(l, statusById) });
        }
        setEdges(built);
    }, [intraLinks, nodeIdOfDevice, statusById, setEdges]);

    // Selection highlight follows the inspector.
    useEffect(() => {
        setNodes((nds) => nds.map((n) => (n.type === 'device' ? { ...n, selected: Number(n.id) === selectedDeviceId } : n)));
    }, [selectedDeviceId, setNodes]);

    // Fit once per map.
    const fittedMap = useRef<number | null>(null);
    useEffect(() => {
        if (activeMapId == null || nodes.length === 0 || fittedMap.current === activeMapId) return;
        fittedMap.current = activeMapId;
        setTimeout(() => fitView({ padding: 0.2, maxZoom: 4, duration: 300 }), 60);
    }, [nodes, activeMapId, fitView]);

    if (config && !config.enabled) {
        return <div className="grid h-full place-items-center text-sm text-white/50">Geo mode needs a tile URL (MYMATE_MAP_TILE_URL) set.</div>;
    }

    return (
        <div className="relative h-full">
            {config?.enabled && <GeoTileLayer baseZoom={baseZoom} tileUrl={config.tile_url} />}
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                proOptions={{ hideAttribution: true }}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onNodeClick={(_, node) => node.type === 'device' && selectDevice(Number(node.id))}
                onNodeDragStop={(_, node) => {
                    if (node.type !== 'device') return;
                    const { lat, lng } = unproject(node.position.x, node.position.y, baseZoom);
                    update.mutate({ id: Number(node.id), latitude: Number(lat.toFixed(7)), longitude: Number(lng.toFixed(7)) });
                }}
                minZoom={0.05}
                maxZoom={12}
                style={{ background: 'transparent' }}
            />
            <div className="absolute left-4 top-4 z-10 flex items-center gap-2">
                <MapSwitcher />
            </div>
            {placed.length === 0 && (
                <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center">
                    <div className="max-w-xs text-center">
                        <MapPin weight="light" className="mx-auto h-7 w-7 text-white/30" />
                        <p className="mt-2 text-sm text-white/70">No devices on this map have coordinates yet</p>
                        <p className="mt-1 text-xs text-white/40">Set a location on a device (its editor), then it shows here.</p>
                    </div>
                </div>
            )}
            {config?.attribution && <div className="pointer-events-none absolute bottom-1 right-2 z-10 rounded bg-black/40 px-1 text-[9px] text-white/50">{config.attribution}</div>}
        </div>
    );
}

/**
 * Geographic mode for a map (GitHub #11): the real React Flow device nodes and links as the
 * foreground, a slippy-map basemap behind them, positioned by each device's coordinates.
 * Co-located devices collapse into a single stack node you can click to fan out.
 */
export function GeoFlowCanvas() {
    return (
        <ReactFlowProvider>
            <GeoFlowInner />
        </ReactFlowProvider>
    );
}
