import { useEffect, useMemo, useRef, useState } from 'react';
import { DownloadSimple, FileCsv } from '@phosphor-icons/react';
import { parseUtc } from '../../../lib/parseUtc';
import { useGraphStyleDefaults } from '../../graphs/api/graphs';
import { GRAPH_PALETTE } from '../../graphs/components/GraphChart';
import { downloadGraphCsv, downloadGraphPng } from '../../graphs/lib/exportGraph';
import { useHistory, type HistoryParams, type HistoryResult } from '../api/devicePage';
import { isMirrored, type GraphSpec } from '../lib/specs';
import type { GraphRange } from '../lib/range';
import { fmtStamp } from '../lib/format';
import { pushToast } from '../../../lib/toast';
import { ChartLegend, chartLegendRows, TimeChart, type ChartLine, type ChartRefLine } from './TimeChart';
import type { GraphData } from '../../../types';

const NO_REFS: ChartRefLine[] = [];

const TIER_LABEL: Record<string, string> = { raw: 'raw samples', '5m': '5 minute rollups', '1h': 'hourly rollups' };

/** True once the element is within a screen of the viewport, false again when it scrolls away. */
function useInView<T extends Element>(margin = '300px 0px') {
    const ref = useRef<T>(null);
    const [inView, setInView] = useState(false);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const io = new IntersectionObserver((entries) => setInView(entries[0].isIntersecting), { rootMargin: margin });
        io.observe(el);
        return () => io.disconnect();
    }, [margin]);
    return [ref, inView] as const;
}

/** Epoch ms of each bucket start. */
function bucketTimes(r: HistoryResult): number[] {
    const t0 = parseUtc(r.origin);
    return Array.from({ length: r.count }, (_, i) => t0 + i * r.step * 1000);
}

/** Re-grid the previous period onto this period's buckets, matching by time offset. */
function alignCompare(main: HistoryResult, cmp: HistoryResult, shiftMs: number, values: (number | null)[]): (number | null)[] {
    const t0 = parseUtc(main.origin);
    const c0 = parseUtc(cmp.origin);
    const step = main.step * 1000;
    const cstep = cmp.step * 1000;
    return Array.from({ length: main.count }, (_, i) => {
        const j = Math.round((t0 + i * step - shiftMs - c0) / cstep);
        return j >= 0 && j < values.length ? values[j] : null;
    });
}

export function HistoryGraph({
    deviceId,
    spec,
    range,
    compare = false,
    onZoom,
    compact = false,
    height,
    points = 360,
    extraRefs = NO_REFS,
    fileBase,
}: {
    deviceId: number;
    spec: GraphSpec;
    range: GraphRange;
    compare?: boolean;
    onZoom?: (fromSec: number, toSec: number) => void;
    compact?: boolean;
    height?: number;
    points?: number;
    extraRefs?: ChartRefLine[];
    fileBase?: string;
}) {
    const [ref, inView] = useInView<HTMLDivElement>();
    const svgRef = useRef<SVGSVGElement>(null);
    const { data: style } = useGraphStyleDefaults();
    const palette = style?.palette?.length ? style.palette : GRAPH_PALETTE;

    const params: HistoryParams = {
        family: spec.family,
        metrics: spec.metrics.map((m) => m.metric),
        keys: spec.keys,
        from: range.from,
        to: range.to,
        points,
        aggregate: spec.aggregate,
        p95: spec.p95 && !compact,
    };
    const span = range.to - range.from;
    const main = useHistory(deviceId, params, inView, range.live);
    const prev = useHistory(deviceId, { ...params, from: range.from - span, to: range.to - span, p95: false }, inView && compare && !compact, false);
    const data = main.data;

    const lines = useMemo<ChartLine[]>(() => {
        if (!data) return [];
        const metricNames = spec.metrics.map((m) => m.metric);
        const multiKey = (spec.keys?.length ?? 0) > 1;
        return data.series.map((s, i) => {
            const mirrored = isMirrored(s.metric, metricNames);
            const keyLabel = s.key !== null ? spec.keyLabels?.[s.key] ?? s.key : null;
            const label = multiKey && keyLabel ? (spec.metrics.length > 1 ? `${keyLabel} ${s.label}` : keyLabel) : s.label;
            const cmpSeries = compare && prev.data ? prev.data.series.find((c) => c.key === s.key && c.metric === s.metric) : undefined;
            return {
                id: `${s.key ?? ''}:${s.metric}`,
                label,
                color: palette[i % palette.length],
                values: s.avg,
                hi: data.tier === 'raw' ? null : s.max,
                lo: null,
                compare: cmpSeries && prev.data ? alignCompare(data, prev.data, span * 1000, cmpSeries.avg) : null,
                mirrored,
                area: spec.area || !!style?.fill,
                stats: s.stats,
            };
        });
    }, [data, prev.data, compare, spec, palette, style?.fill, span]);

    const times = useMemo(() => (data ? bucketTimes(data) : []), [data]);

    const refs = useMemo<ChartRefLine[]>(() => {
        const out: ChartRefLine[] = [];
        if (spec.p95 && !compact) {
            for (const l of lines) {
                if (l.stats?.p95 != null) out.push({ id: `p95:${l.id}`, label: `95th ${l.label.toLowerCase()}`, value: l.stats.p95, color: l.color, mirrored: l.mirrored });
            }
        }
        return [...out, ...extraRefs];
    }, [lines, spec.p95, compact, extraRefs]);

    const unit = spec.unit;
    const name = `${fileBase ?? 'device'}-${spec.title}`.replace(/[^\w.-]+/g, '_');

    function exportCsv() {
        if (!data) return;
        const gd: GraphData = {
            buckets: times.map((t) => new Date(t).toISOString()),
            metric: 'rate',
            series: lines.map((l) => ({ label: `${l.label}${unit ? ` (${unit})` : ''}`, format: 'value', unit, dashed: false, group: l.id, values: l.values })),
            total: null,
        };
        downloadGraphCsv(gd, name);
    }

    // title, chart and the stats legend all in the one image, the svg alone is just the lines
    function exportPng() {
        if (!svgRef.current) return;
        const subtitle = [fileBase, `${fmtStamp(range.from * 1000)} to ${fmtStamp(range.to * 1000)}`].filter(Boolean).join(', ');
        void downloadGraphPng(svgRef.current, name, 2, {
            title: spec.title,
            subtitle,
            legend: lines.length > 0 ? chartLegendRows(lines, unit) : null,
        }).catch(() => pushToast({ title: "Couldn't export the image", tone: 'down' }));
    }

    const body = compact ? (
        <TimeChart times={times} stepMs={(data?.step ?? 60) * 1000} lines={lines} unit={unit} domain={[range.from * 1000, range.to * 1000]} height={height ?? 56} compact loading={main.isLoading && inView} />
    ) : (
        <>
            <TimeChart
                svgRef={svgRef}
                times={times}
                stepMs={(data?.step ?? 60) * 1000}
                lines={lines}
                unit={unit}
                refs={refs}
                domain={[range.from * 1000, range.to * 1000]}
                height={height ?? 190}
                loading={main.isLoading && inView}
                onZoom={onZoom ? (a, b) => onZoom(a / 1000, b / 1000) : undefined}
            />
            {lines.length > 0 && <ChartLegend lines={lines} unit={unit} />}
        </>
    );

    if (compact) return <div ref={ref}>{body}</div>;

    return (
        <div ref={ref} className="rounded-2xl bg-white/[0.02] p-3 ring-1 ring-white/[0.06]">
            <div className="mb-1.5 flex items-center gap-2 px-1">
                <h3 className="min-w-0 flex-1 truncate text-sm font-semibold text-white/85">{spec.title}</h3>
                {data && (
                    <span className="hidden font-mono text-[10px] text-white/30 sm:inline" title="Where these points were read from">
                        {TIER_LABEL[data.tier] ?? data.tier}
                        {data.p95_resolution === '1h' ? ', 95th from hourly data' : ''}
                    </span>
                )}
                {main.isFetching && !main.isLoading && <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400/70" title="Refreshing" />}
                <button type="button" onClick={exportCsv} disabled={!data} title="Download CSV" className="rounded-md p-1 text-white/35 transition-colors hover:bg-white/5 hover:text-white/80 disabled:opacity-30">
                    <FileCsv weight="light" className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={exportPng}
                    disabled={!data}
                    title="Download PNG"
                    className="rounded-md p-1 text-white/35 transition-colors hover:bg-white/5 hover:text-white/80 disabled:opacity-30"
                >
                    <DownloadSimple weight="light" className="h-4 w-4" />
                </button>
            </div>
            {inView || data ? body : <div style={{ height: (height ?? 190) + 40 }} />}
        </div>
    );
}
