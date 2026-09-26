import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ReactFlow, useReactFlow, useNodesState, useEdgesState, type Node, type Edge } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { MapPin } from '@phosphor-icons/react';
import { DeviceNode } from '../../topology/nodes/DeviceNode';
import { UtilEdge } from '../../topology/edges/UtilEdge';
import { OspfCostControl } from '../../topology/components/OspfCostControl';
import { computeData, linkUtil, metaOf } from '../../topology/lib/edgeData';
import { GeoStackNode } from './GeoStackNode';
import { GeoTileLayer } from './GeoTileLayer';
import { LayerToggle, loadLayerPrefs, persistLayerPrefs, type LayerPrefs } from './geoLayers';
import { project, unproject, computeBaseZoom } from '../lib/mercator';
import type { Device, DeviceStatus, Link } from '../../../types';

const nodeTypes = { device: DeviceNode, stack: GeoStackNode };
const edgeTypes = { util: UtilEdge };

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

export type GeoMove = { id: number; lat: number; lng: number };

type Props = {
    devices: Device[] | undefined;
    links: Link[] | undefined;
    memberIds: Set<number>; // the map's devices - only these (and links between them) are drawn
    tileUrl: string | null;
    attribution?: string;
    fitKey: number | string | null; // frames the map once per key (the map id)
    selectedDeviceId?: number | null;
    onDeviceClick?: (id: number) => void;
    onDevicesMoved?: (moves: GeoMove[]) => void; // omit for a read-only view (public wallboard)
    emptyHint: string;
    topLeft?: ReactNode;
};

/**
 * The geo-mode canvas itself (GitHub #11): real React Flow device nodes and links over a slippy-map
 * basemap, positioned by each device's coordinates, with co-located devices collapsed into a stack
 * you click to fan out. Pure presentation - the caller feeds it data, so the authenticated map and
 * the public wallboard (GitHub #37) draw exactly the same thing from their own sources.
 *
 * Links use the same edge data as the logical map, so load colour, bandwidth and the OSPF cost
 * badges (with the operator's size/colour prefs) all carry over. Each can be toggled off for a
 * cleaner planning view (GitHub #22).
 */
export function GeoFlow({
    devices, links, memberIds, tileUrl, attribution, fitKey, selectedDeviceId = null,
    onDeviceClick, onDevicesMoved, emptyHint, topLeft,
}: Props) {
    const { fitView } = useReactFlow();
    const [nodes, setNodes, onNodesChange] = useNodesState<Node>([]);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
    const [expanded, setExpanded] = useState<Set<string>>(new Set());
    // Dragging needs somewhere to save to; clicking/selecting needs an inspector to open. The public
    // wallboard passes neither, so there it's a pure display.
    const readOnly = !onDevicesMoved;
    const clickable = !!onDeviceClick;

    // Link layers, remembered with the other geo layer prefs.
    const [layers, setLayers] = useState<LayerPrefs>(loadLayerPrefs);
    const toggleLayer = (key: keyof LayerPrefs) =>
        setLayers((prev) => {
            const next = { ...prev, [key]: !prev[key] };
            persistLayerPrefs(next);
            return next;
        });

    const placed = useMemo(
        () => (devices ?? []).filter((d) => memberIds.has(d.id) && d.geo_latitude != null && d.geo_longitude != null),
        [devices, memberIds],
    );
    const intraLinks = useMemo(
        () => (links ?? []).filter((l) => memberIds.has(l.a_device_id) && memberIds.has(l.b_device_id)),
        [links, memberIds],
    );
    const hasOspfCost = useMemo(
        () => intraLinks.some((l) => l.a_interface?.ospf_cost != null || l.b_interface?.ospf_cost != null),
        [intraLinks],
    );
    const statusById = useMemo<Record<number, DeviceStatus>>(() => Object.fromEntries((devices ?? []).map((d) => [d.id, d.status])), [devices]);

    // Base zoom for the projection - stable while the *set* of placed devices is unchanged, so
    // dragging a pin (coords change, ids don't) never re-projects the whole map.
    const idsKey = useMemo(() => placed.map((d) => d.id).sort((a, b) => a - b).join(','), [placed]);
    const baseZoom = useMemo(
        () => computeBaseZoom(placed.map((d) => ({ lat: d.geo_latitude as number, lng: d.geo_longitude as number }))),
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
            const k = coordKey(d.geo_latitude as number, d.geo_longitude as number);
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
        () => placed.map((d) => `${d.id}:${d.geo_latitude}:${d.geo_longitude}:${d.status}`).join('|') + '#' + [...expanded].sort().join(',') + '#' + baseZoom.toFixed(3),
        [placed, expanded, baseZoom],
    );
    useEffect(() => {
        const built: Node[] = [];
        for (const [k, g] of nodeIdOfDevice.groups) {
            const [lat, lng] = [g[0].geo_latitude as number, g[0].geo_longitude as number];
            const at = project(lat, lng, baseZoom);
            if (g.length > 1 && !expanded.has(k)) {
                const down = g.filter((d) => d.status === 'down').length;
                built.push({ id: `stack:${k}`, type: 'stack', position: at, data: { count: g.length, down, names: g.map((d) => d.name), onToggle: () => toggleStack(k) }, draggable: false });
            } else if (g.length > 1) {
                // Fanned out around the point so each card is reachable.
                const r = 120;
                g.forEach((d, i) => {
                    const ang = (i / g.length) * Math.PI * 2;
                    built.push({ id: String(d.id), type: 'device', position: { x: at.x + r * Math.cos(ang), y: at.y + r * Math.sin(ang) }, data: deviceData(d), selected: d.id === selectedDeviceId, draggable: !readOnly });
                });
            } else {
                built.push({ id: String(g[0].id), type: 'device', position: at, data: deviceData(g[0]), selected: g[0].id === selectedDeviceId, draggable: !readOnly });
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

    // Edges: each end resolves to its device node, or the stack node while collapsed. Same edge
    // data as the logical map, then the layer toggles strip what the operator has switched off.
    useEffect(() => {
        const built: Edge[] = [];
        if (layers.links) {
            for (const l of intraLinks) {
                const s = nodeIdOfDevice.map.get(l.a_device_id);
                const t = nodeIdOfDevice.map.get(l.b_device_id);
                if (!s || !t || s === t) continue; // both inside the same collapsed stack -> hidden
                const data = { ...computeData(metaOf(l), linkUtil(l), statusById), mediaType: l.media_type, hideLabel: !layers.bandwidth };
                if (!layers.ospf) {
                    data.aCost = null;
                    data.bCost = null;
                }
                built.push({ id: String(l.id), source: s, target: t, type: 'util', selectable: clickable, data });
            }
        }
        setEdges(built);
    }, [intraLinks, nodeIdOfDevice, statusById, setEdges, layers.links, layers.bandwidth, layers.ospf, clickable]);

    // Selection highlight follows the inspector.
    useEffect(() => {
        setNodes((nds) => nds.map((n) => (n.type === 'device' ? { ...n, selected: Number(n.id) === selectedDeviceId } : n)));
    }, [selectedDeviceId, setNodes]);

    // Fit once per map.
    const fitted = useRef<number | string | null>(null);
    useEffect(() => {
        if (fitKey == null || nodes.length === 0 || fitted.current === fitKey) return;
        fitted.current = fitKey;
        setTimeout(() => fitView({ padding: 0.2, maxZoom: 4, duration: 300 }), 60);
    }, [nodes, fitKey, fitView]);

    return (
        <div className="relative h-full">
            {tileUrl && <GeoTileLayer baseZoom={baseZoom} tileUrl={tileUrl} />}
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                proOptions={{ hideAttribution: true }}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                nodesDraggable={!readOnly}
                nodesConnectable={false}
                elementsSelectable={clickable}
                onNodeClick={(_, node) => node.type === 'device' && onDeviceClick?.(Number(node.id))}
                onNodeDragStop={(_, node, dragged) => {
                    if (!onDevicesMoved) return;
                    // A multi-select drag moves several nodes; save each of them, not just the one
                    // under the cursor (GitHub #44).
                    const moves: GeoMove[] = [];
                    for (const n of dragged.length ? dragged : [node]) {
                        if (n.type !== 'device') continue;
                        const { lat, lng } = unproject(n.position.x, n.position.y, baseZoom);
                        moves.push({ id: Number(n.id), lat: Number(lat.toFixed(7)), lng: Number(lng.toFixed(7)) });
                    }
                    if (moves.length) onDevicesMoved(moves);
                }}
                minZoom={0.05}
                maxZoom={12}
                style={{ background: 'transparent' }}
            />
            {topLeft && <div className="absolute left-4 top-4 z-10 flex items-center gap-2">{topLeft}</div>}

            {/* Link layers (GitHub #22): the lines, their bandwidth labels and the OSPF costs can each
                be switched off, eg to plan against the costs alone. Only offered when there's a link. */}
            {intraLinks.length > 0 && (
                <div className="absolute right-4 top-4 z-10 flex flex-wrap items-center justify-end gap-1.5">
                    <LayerToggle label="Links" on={layers.links} onClick={() => toggleLayer('links')} title="Show the links between devices" />
                    {layers.links && (
                        <LayerToggle label="Bandwidth" on={layers.bandwidth} onClick={() => toggleLayer('bandwidth')} title="Show each link's load and capacity" />
                    )}
                    {layers.links && hasOspfCost && (
                        <LayerToggle label="OSPF cost" on={layers.ospf} onClick={() => toggleLayer('ospf')} title="Show the OSPF cost out of each end of a link" />
                    )}
                    {layers.links && hasOspfCost && layers.ospf && <OspfCostControl />}
                </div>
            )}

            {placed.length === 0 && (
                <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center">
                    <div className="max-w-xs text-center">
                        <MapPin weight="light" className="mx-auto h-7 w-7 text-white/30" />
                        <p className="mt-2 text-sm text-white/70">No devices on this map have coordinates yet</p>
                        <p className="mt-1 text-xs text-white/40">{emptyHint}</p>
                    </div>
                </div>
            )}
            {attribution && <div className="pointer-events-none absolute bottom-1 right-2 z-10 rounded bg-black/40 px-1 text-[9px] text-white/50">{attribution}</div>}
        </div>
    );
}
