import { useState } from 'react';
import { Plus, X } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import { useSaveGraph, type GraphInput } from '../api/graphs';
import { pushToast } from '../../../lib/toast';
import type { Graph, GraphDirection, GraphMetric, GraphSeriesDef } from '../../../types';

const field = 'rounded-lg border-0 bg-white/5 px-2.5 py-1.5 text-xs text-white/90 ring-1 ring-white/10 focus:ring-emerald-400/40';

/** Build or edit a saved graph: name, metric, and the interfaces (with direction) it plots. */
export function GraphEditor({ initial, onDone, onCancel }: { initial?: Graph; onDone: () => void; onCancel: () => void }) {
    const { data: devices } = useDevices();
    const save = useSaveGraph();

    const [name, setName] = useState(initial?.name ?? '');
    const [metric, setMetric] = useState<GraphMetric>(initial?.config.metric ?? 'rate');
    const [showTotal, setShowTotal] = useState(initial?.config.show_total ?? true);
    const [series, setSeries] = useState<GraphSeriesDef[]>(initial?.config.series ?? []);

    // The add-a-series row.
    const [deviceId, setDeviceId] = useState<number | null>(null);
    const [interfaceId, setInterfaceId] = useState<number | null>(null);
    const [direction, setDirection] = useState<GraphDirection | 'both'>('both');
    const { data: interfaces } = useDeviceInterfaces(deviceId);

    const ifaceName = (id: number) => interfaces?.find((i) => i.id === id)?.name ?? `if ${id}`;

    function addSeries() {
        if (interfaceId == null) return;
        const dirs: GraphDirection[] = direction === 'both' ? ['in', 'out'] : [direction];
        setSeries((prev) => {
            const next = [...prev];
            for (const d of dirs) {
                if (!next.some((s) => s.interface_id === interfaceId && s.direction === d)) next.push({ interface_id: interfaceId, direction: d });
            }
            return next;
        });
    }

    function submit() {
        if (!name.trim()) { pushToast({ title: 'Give the graph a name', tone: 'down' }); return; }
        if (series.length === 0) { pushToast({ title: 'Add at least one interface', tone: 'down' }); return; }
        const input: GraphInput = { id: initial?.id, name: name.trim(), config: { metric, series, show_total: showTotal } };
        save.mutate(input, {
            onSuccess: () => { pushToast({ title: initial ? 'Graph updated' : 'Graph created', tone: 'up' }); onDone(); },
            onError: () => pushToast({ title: "Couldn't save the graph", tone: 'down' }),
        });
    }

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

            {/* Add a series */}
            <div className="rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
                <p className="mb-2 text-[11px] uppercase tracking-wide text-white/35">Add an interface</p>
                <div className="flex flex-wrap items-center gap-2">
                    <select className={`${field} min-w-40`} value={deviceId ?? ''} onChange={(e) => { setDeviceId(Number(e.target.value) || null); setInterfaceId(null); }}>
                        <option value="">Device...</option>
                        {(devices ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                    <select className={`${field} min-w-40`} value={interfaceId ?? ''} disabled={deviceId == null} onChange={(e) => setInterfaceId(Number(e.target.value) || null)}>
                        <option value="">Interface...</option>
                        {(interfaces ?? []).map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                    </select>
                    <select className={field} value={direction} onChange={(e) => setDirection(e.target.value as GraphDirection | 'both')}>
                        <option value="both">In + Out</option>
                        <option value="in">In</option>
                        <option value="out">Out</option>
                    </select>
                    <button onClick={addSeries} disabled={interfaceId == null} className="flex items-center gap-1.5 rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-400/25 hover:bg-emerald-500/25 disabled:opacity-40">
                        <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                    </button>
                </div>
            </div>

            {/* Current series */}
            <div className="space-y-1">
                {series.length === 0 && <p className="px-1 text-xs text-white/35">No interfaces yet. Add one or more above.</p>}
                {series.map((s, i) => (
                    <div key={`${s.interface_id}:${s.direction}`} className="flex items-center gap-2 rounded-lg bg-white/[0.02] px-3 py-1.5 text-xs ring-1 ring-white/5">
                        <span className="flex-1 truncate text-white/75">{ifaceName(s.interface_id)} <span className="text-white/35">{s.direction}</span></span>
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
