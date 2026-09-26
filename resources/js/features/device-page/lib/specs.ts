import { GROUP_ORDER, groupTitle } from './format';
import type { CatalogFamily, CatalogMetric, HistoryCatalog } from '../api/devicePage';

// Turns the history catalog into the list of graphs a tab shows. Nothing here knows family
// names beyond the interface traffic special case: metrics are grouped by the catalog's group
// and unit, "x_in"/"x_out" pairs are mirrored, and keyed families hang off the port picker
// (owner interfaces), get a graph per key (probes, sensors) or share one graph (everything
// else, eg per-CPU cores). A family the backend adds later lands in the right place by itself.

export interface GraphSpec {
    id: string;
    group: string;
    title: string;
    family: string;
    metrics: CatalogMetric[];
    keys?: string[];
    keyLabels?: Record<string, string>;
    aggregate?: boolean;
    unit: string | null;
    p95?: boolean;
    area?: boolean;
}

export interface GraphSection {
    group: string;
    title: string;
    specs: GraphSpec[];
}

const MAX_SERIES_KEYS = 12;

/** Out below the axis, in above, whenever a spec has a matching pair. */
export function isMirrored(metric: string, metrics: string[]): boolean {
    return metric.endsWith('_out') && metrics.includes(metric.slice(0, -4) + '_in');
}

/** Split a family's metrics into one bucket per (group, unit), in catalog order. */
function byGroupUnit(metrics: CatalogMetric[]): CatalogMetric[][] {
    const out = new Map<string, CatalogMetric[]>();
    for (const m of metrics) {
        const k = `${m.group}|${m.unit ?? ''}`;
        out.set(k, [...(out.get(k) ?? []), m]);
    }
    return [...out.values()];
}

function titleFor(ms: CatalogMetric[]): string {
    if (ms.length === 1) return ms[0].label;
    const pair = ms.length === 2 && ms.every((m) => /_(in|out)$/.test(m.metric));
    if (pair) {
        const rest = ms[0].label.replace(/^In\b\s*/i, '').trim();
        return rest ? rest[0].toUpperCase() + rest.slice(1) : groupTitle(ms[0].group);
    }
    return ms.map((m) => m.label).join(' / ');
}

function portSpecs(f: CatalogFamily, portKey: string, portLabel: string): GraphSpec[] {
    return byGroupUnit(f.metrics).map((ms) => {
        const traffic = ms[0].unit === 'bps';
        return {
            id: `${f.family}:${portKey}:${ms.map((m) => m.metric).join(',')}`,
            group: ms[0].group,
            title: traffic ? `Traffic - ${portLabel}` : `${titleFor(ms) || groupTitle(ms[0].group)} - ${portLabel}`,
            family: f.family,
            metrics: ms,
            keys: [portKey],
            unit: ms[0].unit,
            p95: traffic,
            area: traffic,
        };
    });
}

/**
 * The Graphs tab. `port` is the port picked for the per-port graphs (traffic and anything else
 * keyed by interface); the device total traffic graph is always there.
 */
export function deviceSections(catalog: HistoryCatalog, port: { key: string; label: string } | null): GraphSection[] {
    const specs: GraphSpec[] = [];

    for (const f of catalog.families) {
        if (f.owner === 'interfaces') {
            const bps = f.metrics.filter((m) => m.unit === 'bps');
            if (bps.length > 0 && (f.keys?.length ?? 0) > 0) {
                specs.push({
                    id: `${f.family}:total`,
                    group: bps[0].group,
                    title: 'Traffic - device total',
                    family: f.family,
                    metrics: bps,
                    aggregate: true,
                    unit: 'bps',
                    p95: true,
                    area: true,
                });
            }
            if (port && f.keys?.some((k) => k.key === port.key)) specs.push(...portSpecs(f, port.key, port.label));
            continue;
        }

        if (!f.keyed || !f.keys) {
            for (const ms of byGroupUnit(f.metrics)) {
                specs.push({ id: `${f.family}:${ms.map((m) => m.metric).join(',')}`, group: ms[0].group, title: titleFor(ms), family: f.family, metrics: ms, unit: ms[0].unit });
            }
            continue;
        }

        // Keys that carry their own unit (custom sensors) or are separate things (probes) get a
        // graph each; plain indexes (cores, disks) share one graph per metric.
        const perKey = f.owner !== null || f.keys.some((k) => k.unit !== undefined);
        if (perKey) {
            for (const k of f.keys) {
                for (const ms of byGroupUnit(f.metrics)) {
                    specs.push({
                        id: `${f.family}:${k.key}:${ms.map((m) => m.metric).join(',')}`,
                        group: ms[0].group,
                        title: f.metrics.length > 1 ? `${k.label} - ${titleFor(ms)}` : k.label,
                        family: f.family,
                        metrics: ms,
                        keys: [k.key],
                        keyLabels: { [k.key]: k.label },
                        unit: k.unit ?? ms[0].unit,
                    });
                }
            }
            continue;
        }

        const labels = Object.fromEntries(f.keys.map((k) => [k.key, k.label]));
        for (const m of f.metrics) {
            for (let i = 0; i < f.keys.length; i += MAX_SERIES_KEYS) {
                const chunk = f.keys.slice(i, i + MAX_SERIES_KEYS);
                specs.push({
                    id: `${f.family}:${m.metric}:${i}`,
                    group: m.group,
                    title: f.keys.length > MAX_SERIES_KEYS ? `${m.label} (${i + 1}-${i + chunk.length})` : `${m.label} - per ${(f.key_label ?? 'key').toLowerCase()}`,
                    family: f.family,
                    metrics: [m],
                    keys: chunk.map((k) => k.key),
                    keyLabels: labels,
                    unit: m.unit,
                });
            }
        }
    }

    return toSections(specs);
}

/** The per-port page: every interface-keyed family, for this one port. */
export function portSections(catalog: HistoryCatalog, portKey: string, portLabel: string): GraphSection[] {
    const specs = catalog.families
        .filter((f) => f.owner === 'interfaces' && f.keys?.some((k) => k.key === portKey))
        .flatMap((f) => portSpecs(f, portKey, portLabel));
    return toSections(specs);
}

function toSections(specs: GraphSpec[]): GraphSection[] {
    const groups = new Map<string, GraphSpec[]>();
    for (const s of specs) groups.set(s.group, [...(groups.get(s.group) ?? []), s]);
    const rank = (g: string) => {
        const i = GROUP_ORDER.indexOf(g);
        return i === -1 ? GROUP_ORDER.length : i;
    };
    return [...groups.entries()]
        .sort(([a], [b]) => rank(a) - rank(b) || a.localeCompare(b))
        .map(([group, list]) => ({ group, title: groupTitle(group), specs: list }));
}

/** Find a spec for the Overview sparklines by metric name. */
export function findSpec(sections: GraphSection[], pred: (s: GraphSpec) => boolean): GraphSpec | null {
    for (const sec of sections) for (const s of sec.specs) if (pred(s)) return s;
    return null;
}
