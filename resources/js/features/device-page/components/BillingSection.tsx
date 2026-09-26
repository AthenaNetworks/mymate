import { useEffect, useMemo, useState } from 'react';
import { Warning } from '@phosphor-icons/react';
import { formatRate } from '../../../lib/formatRate';
import { useBilling, type BillingFigures, type BillingPeriod, type BillingResult } from '../api/devicePage';
import { fmtBytes, fmtStamp } from '../lib/format';
import { Card } from './ui';
import type { Device, NetworkInterface } from '../../../types';

const chip = 'rounded-md px-2.5 py-1 text-xs font-medium transition-colors duration-200 ease-fluid';
const field = 'rounded-lg bg-white/[0.04] px-2 py-1 text-xs text-white/85 outline-none ring-1 ring-white/10 focus:ring-emerald-400/50';

const dateInput = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function Row({ label, f, strong }: { label: string; f: BillingFigures; strong?: boolean }) {
    const td = 'px-3 py-2 text-right font-mono tabular-nums';
    return (
        <tr className={`border-b border-white/[0.04] last:border-0 ${strong ? 'bg-white/[0.03] text-white/90' : 'text-white/75'}`}>
            <td className={`px-3 py-2 ${strong ? 'font-semibold' : ''}`}>{label}</td>
            <td className={`${td} text-amber-300/90`}>{formatRate(f.p95_in)}</td>
            <td className={`${td} text-amber-300/90`}>{formatRate(f.p95_out)}</td>
            <td className={`${td} font-semibold text-amber-200`}>{formatRate(f.p95_max)}</td>
            <td className={td}>{fmtBytes(f.bytes_in)}</td>
            <td className={td}>{fmtBytes(f.bytes_out)}</td>
            <td className={`${td} text-white/45`}>{f.samples.toLocaleString()}</td>
        </tr>
    );
}

/**
 * 95th percentile billing for this port (and optionally others summed with it): pick a period,
 * get p95 in / out / max(in, out) over the 5 minute rates and the bytes moved each way. The p95
 * lines can be drawn on the traffic graph above, and the period opened on the graphs.
 */
export function BillingSection({
    device,
    portId,
    ifaces,
    onResult,
    onGraph,
    setOnGraph,
    onShowPeriod,
}: {
    device: Device;
    portId: number;
    ifaces: NetworkInterface[];
    onResult: (r: BillingResult | null) => void;
    onGraph: boolean;
    setOnGraph: (v: boolean) => void;
    onShowPeriod: (fromSec: number, toSec: number) => void;
}) {
    const [period, setPeriod] = useState<BillingPeriod>('this_month');
    const [from, setFrom] = useState(() => dateInput(new Date(Date.now() - 30 * 86_400_000)));
    const [to, setTo] = useState(() => dateInput(new Date()));
    const [extra, setExtra] = useState<Set<number>>(new Set());
    const [picking, setPicking] = useState(false);

    const keys = useMemo(() => [String(portId), ...[...extra].filter((id) => id !== portId).map(String)], [portId, extra]);
    const customFrom = Date.parse(`${from}T00:00:00`) / 1000;
    // the end date is inclusive, so bill up to the start of the next day
    const customTo = Date.parse(`${to}T00:00:00`) / 1000 + 86_400;
    const customOk = Number.isFinite(customFrom) && Number.isFinite(customTo) && customTo > customFrom;

    const { data, isLoading, isFetching, error } = useBilling(
        device.id,
        { period, keys, from: period === 'custom' ? customFrom : undefined, to: period === 'custom' ? customTo : undefined, aggregate: keys.length > 1 },
        period !== 'custom' || customOk,
    );

    useEffect(() => {
        onResult(data ?? null);
    }, [data, onResult]);

    const coverage = data && data.expected_samples > 0 ? Math.min(100, (Math.max(0, ...data.ports.map((p) => p.samples)) / data.expected_samples) * 100) : null;

    return (
        <Card
            title="95th percentile billing"
            right={
                <label className="flex cursor-pointer items-center gap-1.5 text-xs text-white/50">
                    <input type="checkbox" checked={onGraph} onChange={(e) => setOnGraph(e.target.checked)} className="h-3.5 w-3.5 accent-amber-400" />
                    Draw on the traffic graph
                </label>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex gap-0.5 rounded-lg bg-white/[0.04] p-0.5 ring-1 ring-white/10">
                    {(
                        [
                            ['this_month', 'This month'],
                            ['last_month', 'Last month'],
                            ['custom', 'Custom'],
                        ] as [BillingPeriod, string][]
                    ).map(([k, label]) => (
                        <button key={k} type="button" onClick={() => setPeriod(k)} className={`${chip} ${period === k ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80'}`}>
                            {label}
                        </button>
                    ))}
                </div>
                {period === 'custom' && (
                    <>
                        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={field} />
                        <span className="text-xs text-white/40">to</span>
                        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={field} />
                    </>
                )}
                <button type="button" onClick={() => setPicking((p) => !p)} className={`${chip} text-white/55 ring-1 ring-white/10 hover:text-white`}>
                    {extra.size > 0 ? `${keys.length} ports summed` : 'Sum with other ports...'}
                </button>
                {data && (
                    <button
                        type="button"
                        onClick={() => onShowPeriod(Date.parse(data.from) / 1000, Date.parse(data.to) / 1000)}
                        className={`${chip} text-emerald-300/90 ring-1 ring-emerald-400/20 hover:bg-emerald-500/10`}
                    >
                        Show this period on the graphs
                    </button>
                )}
                {isFetching && <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400/70" />}
            </div>

            {picking && (
                <div className="mt-3 max-h-56 overflow-y-auto rounded-xl bg-white/[0.03] p-2 ring-1 ring-white/[0.06]">
                    <p className="px-1 pb-1.5 text-[11px] text-white/40">
                        Pick ports to add to <span className="text-white/70">{ifaces.find((i) => i.id === portId)?.name}</span>. Their rates are summed per 5 minute interval before the percentile is taken.
                    </p>
                    <div className="grid gap-x-3 sm:grid-cols-2 lg:grid-cols-3">
                        {ifaces
                            .filter((i) => i.id !== portId)
                            .map((i) => (
                                <label key={i.id} className="flex cursor-pointer items-center gap-2 rounded-md px-1 py-0.5 text-xs text-white/70 hover:bg-white/[0.03]">
                                    <input
                                        type="checkbox"
                                        checked={extra.has(i.id)}
                                        onChange={() =>
                                            setExtra((prev) => {
                                                const next = new Set(prev);
                                                if (next.has(i.id)) next.delete(i.id);
                                                else next.add(i.id);
                                                return next;
                                            })
                                        }
                                        className="h-3.5 w-3.5 accent-emerald-400"
                                    />
                                    <span className="truncate">{i.name}</span>
                                    {i.description && <span className="truncate text-white/35">{i.description}</span>}
                                </label>
                            ))}
                    </div>
                    {extra.size > 0 && (
                        <button type="button" onClick={() => setExtra(new Set())} className="mt-1 px-1 text-[11px] text-white/45 hover:text-white/80">
                            Clear
                        </button>
                    )}
                </div>
            )}

            {data && !data.precise && (
                <div className="mt-3 flex gap-2 rounded-xl bg-amber-500/[0.07] px-3 py-2 text-xs text-amber-200 ring-1 ring-amber-400/20">
                    <Warning weight="fill" className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-300" />
                    <span>
                        Hourly resolution, less precise. This period starts before the 5 minute rollups are kept, so the 95th percentile is taken over hourly
                        averages, which smooths out bursts and reads lower than a true 5 minute figure. Transfer totals are unaffected.
                    </span>
                </div>
            )}

            {error ? (
                <p className="mt-3 text-xs text-rose-300">Couldn't work out billing for this period.</p>
            ) : isLoading || !data ? (
                <p className="mt-3 text-xs text-white/35">{period === 'custom' && !customOk ? 'Pick a start and end date.' : 'Working it out...'}</p>
            ) : (
                <>
                    <div className="mt-3 overflow-x-auto rounded-xl ring-1 ring-white/[0.06]">
                        <table className="w-full min-w-[40rem] text-xs">
                            <thead className="border-b border-white/[0.06] text-[10px] uppercase tracking-[0.14em] text-white/30">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">Port</th>
                                    <th className="px-3 py-2 text-right font-medium">95th in</th>
                                    <th className="px-3 py-2 text-right font-medium">95th out</th>
                                    <th className="px-3 py-2 text-right font-medium" title="95th percentile of the higher of in and out in each interval, the usual burstable billing figure">
                                        95th max(in, out)
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">Transferred in</th>
                                    <th className="px-3 py-2 text-right font-medium">Transferred out</th>
                                    <th className="px-3 py-2 text-right font-medium">Samples</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.ports.map((p) => (
                                    <Row key={p.key} label={p.label} f={p} />
                                ))}
                                {data.aggregate && <Row label={`Sum of ${data.ports.length} ports`} f={data.aggregate} strong />}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-2 text-[11px] leading-relaxed text-white/35">
                        {data.label}: {fmtStamp(Date.parse(data.from))} to {fmtStamp(Date.parse(data.to))}. {data.resolution === '5m' ? '5 minute' : 'Hourly'} averages, top 5% of
                        intervals dropped, intervals with no samples not counted
                        {coverage !== null ? ` (${coverage.toFixed(coverage < 99.5 ? 1 : 0)}% of the period has data)` : ''}. Transfer is the average rate times the
                        interval length.
                    </p>
                </>
            )}
        </Card>
    );
}
