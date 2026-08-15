import dagre from '@dagrejs/dagre';
import { forceCollide, forceLink, forceManyBody, forceSimulation, forceX, forceY, type SimulationNodeDatum } from 'd3-force';
import type { Device, Link } from '../../../types';

// Node footprint used for spacing - keep in step with DeviceNode\'s rendered size.
const NODE_W = 208;
const NODE_H = 84;
// Extra breathing room enforced between cards by declump (so they don\'t merely touch).
const GAP = 28;

export type LayoutKind = 'smart' | 'tree-tb' | 'tree-lr' | 'radial' | 'force' | 'dependency';
export type Pos = { x: number; y: number };
export type PosMap = Record<number, Pos>;

/** Build the undirected adjacency the layouts share: device parent->child edges plus
 *  links, so even unparented devices fall into the structure. Self/dup edges dropped. */
function buildEdges(devices: Device[], links: Link[]): Array<[number, number]> {
    const ids = new Set(devices.map((d) => d.id));
    const seen = new Set<string>();
    const edges: Array<[number, number]> = [];
    const add = (a: number, b: number) => {
        if (a === b || !ids.has(a) || !ids.has(b) || seen.has(`${a}->${b}`) || seen.has(`${b}->${a}`)) return;
        seen.add(`${a}->${b}`);
        edges.push([a, b]);
    };
    for (const d of devices) if (d.parent_device_id) add(d.parent_device_id, d.id);
    for (const l of links) add(l.a_device_id, l.b_device_id);
    return edges;
}

/**
 * AABB declump - separate any overlapping cards by pushing pairs apart on their
 * least-penetration axis (ties broken by index, so it\'s stable). Ported from the
 * Dude importer\'s normaliser. Mutates a copy; returns overlap-free positions.
 */
export function declump(positions: PosMap, iterations = 80): PosMap {
    const ids = Object.keys(positions).map(Number);
    const xs = ids.map((id) => positions[id].x);
    const ys = ids.map((id) => positions[id].y);
    const cellW = NODE_W + GAP;
    const cellH = NODE_H + GAP;
    const n = ids.length;

    for (let iter = 0; iter < iterations; iter++) {
        let moved = false;
        for (let i = 0; i < n; i++) {
            for (let j = i + 1; j < n; j++) {
                const dx = xs[j] - xs[i];
                const dy = ys[j] - ys[i];
                const ox = cellW - Math.abs(dx);
                const oy = cellH - Math.abs(dy);
                if (ox <= 0 || oy <= 0) continue; // no overlap on at least one axis
                if (ox <= oy) {
                    const push = ox / 2 + 0.5;
                    const dir = dx < 0 ? -1 : dx > 0 ? 1 : i < j ? -1 : 1;
                    xs[i] -= dir * push;
                    xs[j] += dir * push;
                } else {
                    const push = oy / 2 + 0.5;
                    const dir = dy < 0 ? -1 : dy > 0 ? 1 : i < j ? -1 : 1;
                    ys[i] -= dir * push;
                    ys[j] += dir * push;
                }
                moved = true;
            }
        }
        if (!moved) break;
    }

    // Re-origin to a padded top-left and round to whole pixels.
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    const out: PosMap = {};
    ids.forEach((id, i) => {
        out[id] = { x: Math.round(xs[i] - minX + GAP), y: Math.round(ys[i] - minY + GAP) };
    });
    return out;
}

/**
 * Hierarchical (dagre) positions from the device tree. `dir` flows the tree
 * top->bottom (`TB`, default) or left->right (`LR`). Returns device id -> top-left.
 */
export function hierarchicalLayout(devices: Device[], links: Link[], dir: 'TB' | 'LR' = 'TB'): PosMap {
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: dir, nodesep: 56, ranksep: 80, marginx: 48, marginy: 48 });
    g.setDefaultEdgeLabel(() => ({}));

    for (const d of devices) g.setNode(String(d.id), { width: NODE_W, height: NODE_H });
    for (const [a, b] of buildEdges(devices, links)) g.setEdge(String(a), String(b));

    dagre.layout(g);

    const pos: PosMap = {};
    for (const d of devices) {
        const n = g.node(String(d.id));
        if (n) pos[d.id] = { x: Math.round(n.x - NODE_W / 2), y: Math.round(n.y - NODE_H / 2) };
    }
    return pos;
}

// Minimum horizontal gap between two node centres on the same rank.
const SEP = NODE_W + GAP;

/** A laid-out subtree: node->x (root at 0) plus its left/right silhouettes, indexed by
 *  depth-below-this-root, used to slide sibling subtrees together without overlap. */
type Contour = { pos: Map<number, number>; left: number[]; right: number[] };

/**
 * Contour-based tidy layout of one subtree (Walker/Buchheim style). Lays out every child
 * subtree, then slides each next sibling leftwards until its left silhouette just clears
 * the running right silhouette (>= SEP at every shared depth) - so siblings pack as tightly
 * as their shapes allow, not on a fixed grid. The parent is then centred exactly over its
 * children. Crossing-free and deterministic. O(nodes - height), fine for map-sized graphs.
 */
function tidySubtree(id: number, children: Map<number, number[]>): Contour {
    const kids = children.get(id) ?? [];
    if (kids.length === 0) return { pos: new Map([[id, 0]]), left: [0], right: [0] };

    const pos = new Map<number, number>();
    let left: number[] = []; // running silhouettes for the block of placed children
    let right: number[] = [];
    const kidRootX: number[] = [];

    for (let i = 0; i < kids.length; i++) {
        const sub = tidySubtree(kids[i], children);
        // Smallest shift that keeps sub\'s left silhouette >= SEP right of the running right one.
        let shift = 0;
        if (i > 0) {
            let need = -Infinity;
            const overlap = Math.min(right.length, sub.left.length);
            for (let d = 0; d < overlap; d++) need = Math.max(need, right[d] - sub.left[d] + SEP);
            shift = need === -Infinity ? 0 : need;
        }
        for (const [n, x] of sub.pos) pos.set(n, x + shift);
        kidRootX.push(shift); // sub\'s root sat at 0 before the shift

        // Merge silhouettes: the newest child is rightmost where it reaches; the leftmost
        // child stays leftmost where it reaches; deeper-only depths come from whoever reaches.
        const len = Math.max(left.length, sub.left.length);
        const nl = new Array<number>(len);
        const nr = new Array<number>(len);
        for (let d = 0; d < len; d++) {
            nl[d] = d < left.length ? left[d] : sub.left[d] + shift;
            nr[d] = d < sub.right.length ? sub.right[d] + shift : right[d];
        }
        left = nl;
        right = nr;
    }

    // Centre the parent over its outermost children, then re-origin so the parent sits at 0.
    const delta = -(kidRootX[0] + kidRootX[kidRootX.length - 1]) / 2;
    if (delta !== 0) {
        for (const [n, x] of pos) pos.set(n, x + delta);
        for (let d = 0; d < left.length; d++) {
            left[d] += delta;
            right[d] += delta;
        }
    }
    pos.set(id, 0);
    return { pos, left: [0, ...left], right: [0, ...right] };
}

/**
 * Smart / tidy-tree layout - the readable default. It *scans* the graph to find its
 * natural hierarchy and draws a clean, crossing-free tree (think core -> distribution ->
 * access -> CPE), so an operator can follow the topology at a glance instead of untangling
 * crossing wires.
 *
 * How it works:
 *  1. Root-finding: build a spanning forest. Roots are the unparented devices (gateways/
 *     cores), highest-degree first; any leftover component is rooted at its busiest node.
 *  2. Ranking: BFS from each root assigns a depth (rank) - the row the node sits on.
 *  3. Tidy x-placement (`tidySubtree`, contour-based): each node\'s children subtrees are
 *     packed against each other by their silhouettes - as tight as their shapes allow - and
 *     every parent is centred over its children. Sibling subtrees can never overlap and tree
 *     edges never cross; extra (mesh) links beyond the spanning tree simply overlay it.
 *  4. Forest packing: disconnected trees are laid side by side, each cleared of the previous
 *     tree\'s full width.
 *
 * `dir` flows the tree top->bottom (`TB`, default) or left->right (`LR`).
 */
export function smartLayout(devices: Device[], links: Link[], dir: 'TB' | 'LR' = 'TB', preferredRootId?: number | null): PosMap {
    if (devices.length === 0) return {};

    const adj = new Map<number, number[]>();
    for (const d of devices) adj.set(d.id, []);
    for (const [a, b] of buildEdges(devices, links)) {
        adj.get(a)!.push(b);
        adj.get(b)!.push(a);
    }
    const degree = (id: number) => adj.get(id)!.length;

    const YSTEP = NODE_H + GAP + 64; // rank-to-rank pitch (clear horizontal lanes)

    // Roots: unparented devices first (the cores), then any still-unplaced node - each
    // group busiest-first, id as a stable tiebreak, so the layout is deterministic. A
    // preferred root (e.g. the north-most device) is forced to the front so it tops the tree.
    const preferred = preferredRootId != null ? devices.find((d) => d.id === preferredRootId) : undefined;
    const rootOrder = [
        ...(preferred ? [preferred] : []),
        ...[
            ...devices.filter((d) => !d.parent_device_id),
            ...devices.filter((d) => d.parent_device_id),
        ]
            .filter((d) => d.id !== preferredRootId)
            .sort((p, q) => degree(q.id) - degree(p.id) || p.id - q.id),
    ];

    const placed = new Set<number>();
    const pos: PosMap = {};
    let originX = 0; // left edge for the next disconnected tree

    for (const root of rootOrder) {
        if (placed.has(root.id)) continue;

        // BFS spanning tree from this root: record each node\'s depth + ordered children.
        // Children are arranged biggest-subtree-outermost so the tree looks balanced.
        const depth = new Map<number, number>([[root.id, 0]]);
        const children = new Map<number, number[]>();
        placed.add(root.id);
        const queue = [root.id];
        while (queue.length) {
            const cur = queue.shift()!;
            const kids = (adj.get(cur) ?? [])
                .filter((nb) => !placed.has(nb))
                .sort((a, b) => degree(b) - degree(a) || a - b);
            children.set(cur, kids);
            for (const k of kids) {
                placed.add(k);
                depth.set(k, depth.get(cur)! + 1);
                queue.push(k);
            }
        }

        const { pos: rel } = tidySubtree(root.id, children);
        const minX = Math.min(...rel.values());
        for (const [id, x] of rel) {
            const across = originX + (x - minX);
            const down = depth.get(id)! * YSTEP;
            pos[id] = dir === 'TB' ? { x: across, y: down } : { x: down, y: across };
        }
        const span = Math.max(...rel.values()) - minX;
        originX += span + NODE_W + GAP; // clear the whole tree before the next one
    }
    return pos;
}

/**
 * Dependency tidy - incremental and rooted. With a `rootId`, it re-lays-out ONLY that device's
 * downstream branch (its descendants in the map's dependency tree) as a clean top-down tree,
 * anchored so the root keeps its *current* position - everything north/unrelated is left exactly
 * where it is. Returns positions for the branch only, so the caller's per-device save touches
 * nothing else. With no `rootId`, tidies the whole map rooted at the most-north (min-y) device.
 */
export function dependencyLayout(devices: Device[], links: Link[], rootId: number | null, current: PosMap = {}): PosMap {
    if (devices.length === 0) return {};

    // No root chosen: whole-map tidy, topped by the most-north device the operator left up there.
    if (rootId == null || !devices.some((d) => d.id === rootId)) {
        let north: number | null = null;
        let northY = Infinity;
        for (const d of devices) {
            const y = current[d.id]?.y ?? 0;
            if (y < northY) {
                northY = y;
                north = d.id;
            }
        }
        return smartLayout(devices, links, 'TB', north);
    }

    // Build the map's dependency tree (spanning forest from the natural roots) so every node has a
    // parent/children relationship - the same BFS smartLayout uses.
    const adj = new Map<number, number[]>();
    for (const d of devices) adj.set(d.id, []);
    for (const [a, b] of buildEdges(devices, links)) {
        adj.get(a)!.push(b);
        adj.get(b)!.push(a);
    }
    const degree = (id: number) => adj.get(id)!.length;

    const children = new Map<number, number[]>();
    const depth = new Map<number, number>();
    const placed = new Set<number>();
    const rootOrder = [
        ...devices.filter((d) => !d.parent_device_id),
        ...devices.filter((d) => d.parent_device_id),
    ]
        .sort((p, q) => degree(q.id) - degree(p.id) || p.id - q.id)
        .map((d) => d.id);
    for (const r of rootOrder) {
        if (placed.has(r)) continue;
        placed.add(r);
        depth.set(r, 0);
        const queue = [r];
        while (queue.length) {
            const cur = queue.shift()!;
            const kids = (adj.get(cur) ?? []).filter((nb) => !placed.has(nb)).sort((a, b) => degree(b) - degree(a) || a - b);
            children.set(cur, kids);
            for (const k of kids) {
                placed.add(k);
                depth.set(k, depth.get(cur)! + 1);
                queue.push(k);
            }
        }
    }

    // Lay out the root's subtree (root + descendants) and translate so the root stays put.
    const YSTEP = NODE_H + GAP + 64;
    const { pos: rel } = tidySubtree(rootId, children);
    const anchor = current[rootId] ?? { x: 0, y: 0 };
    const rootRelX = rel.get(rootId) ?? 0;
    const rootDepth = depth.get(rootId) ?? 0;
    const out: PosMap = {};
    for (const [id, x] of rel) {
        out[id] = { x: Math.round(anchor.x + (x - rootRelX)), y: Math.round(anchor.y + (depth.get(id)! - rootDepth) * YSTEP) };
    }
    return out;
}

/**
 * Radial / balloon layout: the root (an unparented device, else the highest-degree
 * one) sits at the centre; every other device is placed on a ring whose radius grows
 * with its BFS depth, with an angular slot per node. Good for star/hub topologies.
 */
export function radialLayout(devices: Device[], links: Link[]): PosMap {
    if (devices.length === 0) return {};
    const edges = buildEdges(devices, links);
    const adj = new Map<number, number[]>();
    for (const d of devices) adj.set(d.id, []);
    for (const [a, b] of edges) {
        adj.get(a)!.push(b);
        adj.get(b)!.push(a);
    }

    // Root: first device with no parent, else the most-connected, else the first.
    const root =
        devices.find((d) => !d.parent_device_id)?.id ??
        [...devices].sort((p, q) => (adj.get(q.id)!.length - adj.get(p.id)!.length))[0]?.id ??
        devices[0].id;

    // BFS to assign each device a ring depth (unreached nodes get a far outer ring).
    const depth = new Map<number, number>([[root, 0]]);
    const queue = [root];
    while (queue.length) {
        const cur = queue.shift()!;
        for (const nb of adj.get(cur) ?? []) {
            if (!depth.has(nb)) {
                depth.set(nb, depth.get(cur)! + 1);
                queue.push(nb);
            }
        }
    }
    let maxDepth = 0;
    for (const d of depth.values()) maxDepth = Math.max(maxDepth, d);
    const outerRing = maxDepth + 1;

    // Group by ring, then spread each ring evenly around its circle.
    const byRing = new Map<number, number[]>();
    for (const d of devices) {
        const r = depth.get(d.id) ?? outerRing;
        if (!byRing.has(r)) byRing.set(r, []);
        byRing.get(r)!.push(d.id);
    }

    const RING = NODE_W + GAP + 120; // radius step between rings
    const pos: PosMap = {};
    for (const [ring, members] of byRing) {
        if (ring === 0) {
            pos[members[0]] = { x: 0, y: 0 };
            continue;
        }
        const radius = ring * RING;
        members.forEach((id, i) => {
            const angle = (2 * Math.PI * i) / members.length - Math.PI / 2;
            pos[id] = { x: Math.round(radius * Math.cos(angle)), y: Math.round(radius * Math.sin(angle)) };
        });
    }
    return pos;
}

type ForceNode = SimulationNodeDatum & { id: number };

/**
 * Force-directed (organic) layout: a d3-force simulation that repels nodes, pulls
 * linked nodes together, and resolves collisions sized to the node box. Seeded from
 * the current positions and run for a fixed tick count so it\'s deterministic (no
 * Math.random) and snappy. Best for meshy, non-tree networks.
 */
export function forceLayout(devices: Device[], links: Link[], current: PosMap = {}): PosMap {
    if (devices.length === 0) return {};
    // Seed deterministically: keep any known position, else fan nodes out on a ring by
    // index (avoids a degenerate all-at-origin start without Math.random).
    const nodes: ForceNode[] = devices.map((d, i) => {
        const seed = current[d.id];
        const angle = (2 * Math.PI * i) / devices.length;
        const R = 200 + devices.length * 8;
        return { id: d.id, x: seed?.x ?? R * Math.cos(angle), y: seed?.y ?? R * Math.sin(angle) };
    });
    const linkData = buildEdges(devices, links).map(([source, target]) => ({ source, target }));

    const sim = forceSimulation<ForceNode>(nodes)
        .force(
            'link',
            forceLink<ForceNode, { source: number; target: number }>(linkData)
                .id((n) => n.id)
                .distance(NODE_W + GAP + 60)
                .strength(0.6),
        )
        .force('charge', forceManyBody().strength(-1400))
        .force('collide', forceCollide(Math.hypot(NODE_W, NODE_H) / 2 + GAP))
        .force('x', forceX(0).strength(0.04))
        .force('y', forceY(0).strength(0.04))
        .stop();

    sim.tick(400); // run synchronously to convergence

    const pos: PosMap = {};
    for (const n of nodes) pos[n.id] = { x: Math.round(n.x ?? 0), y: Math.round(n.y ?? 0) };
    return pos;
}

/**
 * Dispatch to the requested algorithm and guarantee an overlap-free result. `current`
 * feeds the force layout a deterministic seed. The single entry point MapCanvas calls.
 */
export function computeLayout(kind: LayoutKind, devices: Device[], links: Link[], current: PosMap = {}): PosMap {
    const raw =
        kind === 'smart'
            ? smartLayout(devices, links, 'TB')
            : kind === 'tree-tb'
              ? hierarchicalLayout(devices, links, 'TB')
              : kind === 'tree-lr'
                ? hierarchicalLayout(devices, links, 'LR')
                : kind === 'radial'
                  ? radialLayout(devices, links)
                  : forceLayout(devices, links, current);
    return declump(raw);
}
