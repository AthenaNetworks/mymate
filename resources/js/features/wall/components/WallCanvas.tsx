import { useEffect, useMemo } from 'react';
import {
    ReactFlow,
    Background,
    BackgroundVariant,
    ConnectionMode,
    useReactFlow,
    type Node,
    type Edge,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useTheme } from '../../../lib/theme';
import { DeviceNode } from '../../topology/nodes/DeviceNode';
import { ChildMapNode } from '../../topology/nodes/ChildMapNode';
import { MapNoteNode } from '../../topology/nodes/MapNoteNode';
import { UtilEdge } from '../../topology/edges/UtilEdge';
import { MapLinkEdge } from '../../topology/edges/MapLinkEdge';
import { ChildLinkEdge } from '../../topology/edges/ChildLinkEdge';
import { computeData, linkUtil, metaOf } from '../../topology/lib/edgeData';
import { useWallDevices, useWallLinks, useWallMap } from '../api/wall';
import type { DeviceStatus } from '../../../types';

// Read-only presentation of one map for the public wallboard (GitHub #15). Reuses the exact node
// and edge components the interactive canvas uses, so it looks identical, but builds everything
// straight from the polled public data - no drag, no edit, no admin controls, no subscriptions.
const nodeTypes = { device: DeviceNode, childmap: ChildMapNode, note: MapNoteNode };
const edgeTypes = { util: UtilEdge, mapLink: MapLinkEdge, childLink: ChildLinkEdge };

const CHILD_PREFIX = 'childmap:';
const childNodeId = (mapId: number) => `${CHILD_PREFIX}${mapId}`;

export function WallCanvas() {
    const { data: mapDetail } = useWallMap();
    const { data: devices } = useWallDevices();
    const { data: links } = useWallLinks();
    const { fitView } = useReactFlow();
    const theme = useTheme();

    const posById = useMemo<Record<number, { x: number; y: number }>>(() => {
        const m: Record<number, { x: number; y: number }> = {};
        for (const p of mapDetail?.positions ?? []) m[p.device_id] = { x: p.x, y: p.y };
        return m;
    }, [mapDetail]);
    const memberSet = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);
    const mapDevices = useMemo(() => (devices ?? []).filter((d) => memberSet.has(d.id)), [devices, memberSet]);
    const intraLinks = useMemo(
        () => (links ?? []).filter((l) => memberSet.has(l.a_device_id) && memberSet.has(l.b_device_id)),
        [links, memberSet],
    );
    const childMaps = useMemo(() => mapDetail?.child_maps ?? [], [mapDetail]);
    const mapLinks = useMemo(() => mapDetail?.map_links ?? [], [mapDetail]);
    const mapNotes = useMemo(() => mapDetail?.map_notes ?? [], [mapDetail]);
    const childDeviceLinks = useMemo(() => mapDetail?.child_device_links ?? [], [mapDetail]);

    const statusById = useMemo<Record<number, DeviceStatus>>(
        () => Object.fromEntries((devices ?? []).map((d) => [d.id, d.status])),
        [devices],
    );

    // Nodes rebuild straight from the latest poll - there's no selection or drag state to preserve
    // here (that's the interactive canvas's concern), so a plain derive is enough.
    const nodes = useMemo<Node[]>(() => {
        const deviceNodes: Node[] = mapDevices.map((d) => ({
            id: String(d.id),
            type: 'device',
            position: posById[d.id] ?? { x: d.map_x, y: d.map_y },
            draggable: false,
            connectable: false,
            data: {
                label: d.name,
                mgmt_ip: d.mgmt_ip,
                status: d.status,
                device_type: d.device_type,
                icon: d.icon,
                icon_color: d.icon_color,
                vendor: d.vendor,
                model: d.model,
                util: null,
                load: null,
                cpu: d.cpu_pct,
                mem: d.mem_used_pct,
                temp: d.temp_c,
                rtt_ms: d.rtt_ms,
                loss_pct: d.loss_pct,
                latency_good_ms: d.latency_good_ms,
                latency_bad_ms: d.latency_bad_ms,
            },
        }));
        // Overview child-map nodes (display only - no detach, no navigation off the shared map).
        const childNodes: Node[] = childMaps.map((c, i) => ({
            id: childNodeId(c.id),
            type: 'childmap',
            position: { x: c.node_x ?? 40 + (i % 5) * 240, y: c.node_y ?? 40 + Math.floor(i / 5) * 140 },
            draggable: false,
            connectable: false,
            data: { mapId: c.id, name: c.name, deviceCount: c.device_count },
        }));
        const noteNodes: Node[] = mapNotes.map((n) => ({
            id: `note:${n.id}`,
            type: 'note',
            position: { x: n.x, y: n.y },
            draggable: false,
            connectable: false,
            data: { noteId: n.id, text: n.text, color: n.color, editable: false },
        }));
        return [...deviceNodes, ...childNodes, ...noteNodes];
    }, [mapDevices, posById, childMaps, mapNotes]);

    const edges = useMemo<Edge[]>(() => {
        const utilEdges: Edge[] = intraLinks.map((l) => ({
            id: String(l.id),
            source: String(l.a_device_id),
            target: String(l.b_device_id),
            sourceHandle: l.a_handle ?? undefined,
            targetHandle: l.b_handle ?? undefined,
            type: 'util',
            selectable: false,
            deletable: false,
            data: { ...computeData(metaOf(l), linkUtil(l), statusById), mediaType: l.media_type },
        }));
        const mapLinkEdges: Edge[] = mapLinks.map((ml) => ({
            id: `maplink:${ml.id}`,
            source: childNodeId(ml.a_map_id),
            target: childNodeId(ml.b_map_id),
            sourceHandle: ml.a_handle ?? undefined,
            targetHandle: ml.b_handle ?? undefined,
            type: 'mapLink',
            selectable: false,
            deletable: false,
            data: { mediaType: ml.media_type, label: ml.label },
        }));
        const childLinkEdges: Edge[] = childDeviceLinks.map((cl) => ({
            id: `childlink:${cl.a_map_id}-${cl.b_map_id}`,
            source: childNodeId(cl.a_map_id),
            target: childNodeId(cl.b_map_id),
            type: 'childLink',
            selectable: false,
            deletable: false,
            data: { count: cl.count },
        }));
        return [...utilEdges, ...mapLinkEdges, ...childLinkEdges];
    }, [intraLinks, mapLinks, childDeviceLinks, statusById]);

    // Frame the topology once it has loaded (and never again - a wallboard shouldn't jump on
    // every 5s poll). Keyed on the node count going from 0 to non-zero.
    const ready = nodes.length > 0;
    useEffect(() => {
        if (!ready) return;
        const t = setTimeout(() => fitView({ padding: 0.2, duration: 600 }), 160);
        return () => clearTimeout(t);
    }, [ready, fitView]);

    return (
        <ReactFlow
            nodes={nodes}
            edges={edges}
            nodeTypes={nodeTypes}
            edgeTypes={edgeTypes}
            proOptions={{ hideAttribution: true }}
            connectionMode={ConnectionMode.Loose}
            nodesDraggable={false}
            nodesConnectable={false}
            elementsSelectable={false}
            fitView
            fitViewOptions={{ padding: 0.2 }}
            minZoom={0.1}
            colorMode={theme === 'light' ? 'light' : 'dark'}
        >
            <Background id="major" variant={BackgroundVariant.Lines} gap={128} lineWidth={1} color="rgba(255,255,255,0.028)" />
            <Background id="minor" variant={BackgroundVariant.Dots} gap={32} size={1} color="rgba(255,255,255,0.05)" />
        </ReactFlow>
    );
}
