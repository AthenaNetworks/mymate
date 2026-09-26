import { useMemo } from 'react';
import { useHistoryCatalog } from '../api/devicePage';
import { useDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import { deviceSections } from '../lib/specs';
import { useGraphRange } from '../lib/range';
import { setQuery } from '../lib/location';
import { HistoryGraph } from './HistoryGraph';
import { RangeBar } from './RangeBar';
import { Empty } from './ui';
import type { Device } from '../../../types';

/**
 * Every history the device has, grouped (traffic, latency, CPU, memory...), all sharing the
 * range in the URL and one crosshair. Graphs only fetch while they're on screen.
 */
export function GraphsTab({ device, params }: { device: Device; params: URLSearchParams }) {
    const { data: catalog, isLoading } = useHistoryCatalog(device.id);
    const { data: ifaces } = useDeviceInterfaces(device.id);
    const control = useGraphRange(params);

    const ports = useMemo(() => catalog?.families.find((f) => f.owner === 'interfaces' && f.metrics.some((m) => m.unit === 'bps'))?.keys ?? [], [catalog]);

    // the picked port, else the busiest one right now, else the first
    const portKey = useMemo(() => {
        const asked = params.get('port');
        if (asked && ports.some((p) => p.key === asked)) return asked;
        const load = (id: string) => {
            const i = ifaces?.find((x) => String(x.id) === id);
            return (i?.bps_in ?? 0) + (i?.bps_out ?? 0);
        };
        return [...ports].sort((a, b) => load(b.key) - load(a.key))[0]?.key ?? null;
    }, [params, ports, ifaces]);
    const port = ports.find((p) => p.key === portKey) ?? null;

    const sections = useMemo(() => (catalog ? deviceSections(catalog, port ? { key: port.key, label: port.label } : null) : []), [catalog, port]);
    const zoom = (a: number, b: number) => control.setWindow(a, b);

    return (
        <div className="space-y-4">
            <div className="sticky top-0 z-20 -mx-4 space-y-2 bg-surface-deep/90 px-4 py-2 backdrop-blur-xl sm:-mx-6 sm:px-6">
                <RangeBar control={control} maxDays={catalog?.max_days} />
                {ports.length > 0 && (
                    <div className="flex items-center gap-2 text-xs text-white/50">
                        <span>Port</span>
                        <select
                            value={portKey ?? ''}
                            onChange={(e) => setQuery({ port: e.target.value })}
                            className="max-w-[20rem] rounded-lg bg-white/[0.04] px-2 py-1 text-xs text-white/85 outline-none ring-1 ring-white/10 focus:ring-emerald-400/50"
                        >
                            {ports.map((p) => (
                                <option key={p.key} value={p.key}>
                                    {p.label}
                                    {p.description ? ` - ${p.description}` : ''}
                                </option>
                            ))}
                        </select>
                        <span className="text-white/30">for the per-port graphs</span>
                    </div>
                )}
            </div>

            {isLoading ? (
                <p className="text-sm text-white/35">Loading...</p>
            ) : sections.length === 0 ? (
                <Empty>No history recorded for this device yet. Graphs appear once it has been polled for a while.</Empty>
            ) : (
                sections.map((sec) => (
                    <section key={sec.group} className="space-y-3">
                        <h2 className="text-[11px] font-medium uppercase tracking-[0.2em] text-white/35">{sec.title}</h2>
                        <div className="grid gap-3 xl:grid-cols-2">
                            {sec.specs.map((spec) => (
                                <HistoryGraph key={spec.id} deviceId={device.id} spec={spec} range={control.range} compare={control.compare} onZoom={zoom} fileBase={device.name} />
                            ))}
                        </div>
                    </section>
                ))
            )}
        </div>
    );
}
