import { useState } from 'react';
import { Plus, X } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import { useProbes } from '../../topology/api/probes';
import { useSensors } from '../../settings/api/sensors';
import { useSaveGraph, type GraphInput } from '../api/graphs';
import { pushToast } from '../../../lib/toast';
import type { Graph, GraphDirection, GraphMetric, GraphSeriesDef, GraphSource } from '../../../types';

const field = 'rounded-lg border-0 bg-white/5 px-2.5 py-1.5 text-xs text-white/90 ring-1 ring-white/10 focus:ring-emerald-400/40 disabled:opacity-40';

const SOURCES: { key: GraphSource; label: string }[] = [
    { key: 'interface', label: 'Interface' },
    { key: 'sensor', label: 'Custom OID' },
    { key: 'ping', label: 'Ping latency' },
    { key: 'probe', label: 'Probe latency' },
];

/** Build or edit a saved graph: name, metric, and the series (from any source) it plots. */
export function GraphEditor({ initial, onDone, onCancel }: { initial?: Graph; onDone: () => void; onCancel: () => void }) {
    const { data: devices } = useDevices();
    const { data: sensors } = useSensors();
    const save = useSaveGraph();

    const [name, setName] = useState(initial?.name ?? '');
    const [metric, setMetric] = useState<GraphMetric>(initial?.config.metric ?? 'rate');
    const [showTotal, setShowTotal] = useState(initial?.config.show_total ?? true);
    const [series, setSeries] = useState<GraphSeriesDef[]>(initial?.config.series ?? []);

    // The add-a-series row.
    const [source, setSource] = useState<GraphSource>('interface');
    const [deviceId, setDeviceId] = useState<number | null>(null);
    const [interfaceId, setInterfaceId] = useState<number | null>(null);
    const [direction, setDirection] = useState<GraphDirection | 'both'>('both');
    const [sensorId, setSensorId] = useState<number | null>(null);
    const [probeId, setProbeId] = useState<number | null>(null);
    const { data: interfaces } = useDeviceInterfaces(source === 'interface' && deviceId ? deviceId : null);
    const { data: probes } = useProbes(source === 'probe' && deviceId ? deviceId : null);

    const devName = (id: number | null) => devices?.find((d) => d.id === id)?.name ?? 'device';

    function addSeries() {
        const next: GraphSeriesDef[] = [];
        if (source === 'interface') {
            if (deviceId == null || interfaceId == null) return;
            const ifName = interfaces?.find((i) => i.id === interfaceId)?.name ?? 'if';
            const dirs: GraphDirection[] = direction === 'both' ? ['in', 'out'] : [direction];
            for (const d of dirs) next.push({ source: 'interface', interface_id: interfaceId, direction: d, label: `${devName(deviceId)} ${ifName} ${d}` });
        } else if (source === 'sensor') {
            if (sensorId == null || deviceId == null) return;
            const sName = sensors?.find((s) => s.id === sensorId)?.name ?? 'sensor';
            next.push({ source: 'sensor', sensor_id: sensorId, device_id: deviceId, label: `${sName} (${devName(deviceId)})` });
        } else if (source === 'ping') {
            if (deviceId == null) return;
            next.push({ source: 'ping', device_id: deviceId, label: `${devName(deviceId)} ping` });
        } else if (source === 'probe') {
            if (probeId == null) return;
            const pName = probes?.find((p) => p.id === probeId)?.name ?? 'probe';
            next.push({ source: 'probe', probe_id: probeId, label: `${devName(deviceId)} ${pName}` });
        }
        setSeries((prev) => [...prev, ...next.filter((n) => !prev.some((p) => JSON.stringify({ ...p, label: 0 }) === JSON.stringify({ ...n, label: 0 })))]);
    }

    function submit() {
        if (!name.trim()) { pushToast({ title: 'Give the graph a name', tone: 'down' }); return; }
        if (series.length === 0) { pushToast({ title: 'Add at least one series', tone: 'down' }); return; }
        const input: GraphInput = { id: initial?.id, name: name.trim(), config: { metric, series, show_total: showTotal } };
        save.mutate(input, {
            onSuccess: () => { pushToast({ title: initial ? 'Graph updated' : 'Graph created', tone: 'up' }); onDone(); },
            onError: () => pushToast({ title: "Couldn't save the graph", tone: 'down' }),
        });
    }

    const seriesLabel = (s: GraphSeriesDef) => s.label ?? `${s.source} ${s.interface_id ?? s.sensor_id ?? s.probe_id ?? s.device_id ?? ''}`;

    return (
        <div className="mx-auto max-w-3xl space-y-4 p-4">
            <div className="flex items-center gap-3">
                <input className={`${field} flex-1 text-sm`} placeholder="Graph name (e.g. Internet uplinks)" value={name} onChange={(e) => setName(e.target.value)} />
                <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                    {(['rate', 'util'] as GraphMetric[]).map((m) => (
                        <button key={m} onClick={() => setMetric(m)} className={`rounded-full px-3 py-1 text-xs font-medium ${metric === m ? 'bg-white/10 text-emerald-300 ring-1 ring-white/15' : 'text-white/50 hover:text-white/80'}`}>
                            {m === 'rate' ? 'Throughput' : 'Util %'}
                        </button>
                    ))}
                </div>
            </div>
            <p className="px-1 text-[11px] text-white/35">Throughput / Util applies to interface series. Sensors show their value; ping and probes show latency in ms.</p>

            {/* Add a series */}
            <div className="rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
                <p className="mb-2 text-[11px] uppercase tracking-wide text-white/35">Add a series</p>
                <div className="flex flex-wrap items-center gap-2">
                    <select className={field} value={source} onChange={(e) => { setSource(e.target.value as GraphSource); setInterfaceId(null); setSensorId(null); setProbeId(null); }}>
                        {SOURCES.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
                    </select>

                    {source === 'sensor' ? (
                        <select className={`${field} min-w-40`} value={sensorId ?? ''} onChange={(e) => setSensorId(Number(e.target.value) || null)}>
                            <option value="">OID...</option>
                            {(sensors ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    ) : null}

                    <select className={`${field} min-w-40`} value={deviceId ?? ''} onChange={(e) => { setDeviceId(Number(e.target.value) || null); setInterfaceId(null); setProbeId(null); }}>
                        <option value="">Device...</option>
                        {(devices ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>

                    {source === 'interface' && (
                        <>
                            <select className={`${field} min-w-36`} value={interfaceId ?? ''} disabled={deviceId == null} onChange={(e) => setInterfaceId(Number(e.target.value) || null)}>
                                <option value="">Interface...</option>
                                {(interfaces ?? []).map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                            </select>
                            <select className={field} value={direction} onChange={(e) => setDirection(e.target.value as GraphDirection | 'both')}>
                                <option value="both">In + Out</option>
                                <option value="in">In</option>
                                <option value="out">Out</option>
                            </select>
                        </>
                    )}

                    {source === 'probe' && (
                        <select className={`${field} min-w-36`} value={probeId ?? ''} disabled={deviceId == null} onChange={(e) => setProbeId(Number(e.target.value) || null)}>
                            <option value="">Probe...</option>
                            {(probes ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                    )}

                    <button onClick={addSeries} className="flex items-center gap-1.5 rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/25 hover:bg-emerald-500/25">
                        <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                    </button>
                </div>
            </div>

            {/* Current series */}
            <div className="space-y-1">
                {series.length === 0 && <p className="px-1 text-xs text-white/35">No series yet. Add interfaces, custom OIDs, ping or probe latency above.</p>}
                {series.map((s, i) => (
                    <div key={i} className="flex items-center gap-2 rounded-lg bg-white/[0.02] px-3 py-1.5 text-xs ring-1 ring-white/5">
                        <span className="shrink-0 rounded bg-white/5 px-1.5 text-[10px] uppercase text-white/40">{s.source}</span>
                        <span className="flex-1 truncate text-white/75">{seriesLabel(s)}</span>
                        <button onClick={() => setSeries((prev) => prev.filter((_, j) => j !== i))} className="rounded p-1 text-white/35 hover:text-rose-300"><X weight="bold" className="h-3 w-3" /></button>
                    </div>
                ))}
            </div>

            <label className="flex items-center gap-2 px-1 text-xs text-white/65">
                <input type="checkbox" checked={showTotal} onChange={(e) => setShowTotal(e.target.checked)} className="h-3.5 w-3.5 rounded border-white/20 bg-white/[0.05] text-emerald-500" />
                Show a combined total line (sum of all series)
            </label>

            <div className="flex justify-end gap-2">
                <button onClick={onCancel} className="rounded-lg px-3 py-1.5 text-sm text-white/55 hover:text-white/85">Cancel</button>
                <button onClick={submit} disabled={save.isPending} className="rounded-lg bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 hover:bg-emerald-400 disabled:opacity-40">Save graph</button>
            </div>
        </div>
    );
}
