import { useState } from 'react';
import { Broadcast, Plus, PencilSimple, Trash } from '@phosphor-icons/react';
import { useSensors, useSaveSensor, useDeleteSensor, type SensorInput } from '../api/sensors';
import { ScopeEditor } from '../../../components/ScopeEditor';
import { pushToast } from '../../../lib/toast';
import type { AlertScope, Sensor } from '../../../types';

const card = 'rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60';

function scopeSummary(scope: AlertScope): string {
    switch (scope.type) {
        case 'device_type':
            return `type: ${scope.device_type ?? '-'}`;
        case 'map':
            return 'on a map';
        case 'devices':
            return `${scope.device_ids?.length ?? 0} device(s)`;
        default:
            return 'all devices';
    }
}

function SensorForm({ initial, onDone }: { initial?: Sensor; onDone: () => void }) {
    const save = useSaveSensor();
    const [form, setForm] = useState<SensorInput>({
        id: initial?.id,
        name: initial?.name ?? '',
        oid: initial?.oid ?? '',
        mode: initial?.mode ?? 'get',
        agg: initial?.agg ?? 'sum',
        unit: initial?.unit ?? '',
        divisor: initial?.divisor ?? 1,
        scope: initial?.scope ?? { type: 'all' },
        enabled: initial?.enabled ?? true,
    });
    const set = <K extends keyof SensorInput>(k: K, v: SensorInput[K]) => setForm((f) => ({ ...f, [k]: v }));

    function submit() {
        save.mutate(form, {
            onSuccess: () => {
                pushToast({ title: initial ? 'Sensor updated' : 'Sensor added', tone: 'info' });
                onDone();
            },
            onError: () => pushToast({ title: 'Couldn\'t save sensor', detail: 'Check the OID is numeric.', tone: 'down' }),
        });
    }

    return (
        <div className="space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <input className={field} placeholder="Name (e.g. WAN in-errors)" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <input className={`${field} font-mono`} placeholder="OID (e.g. 1.3.6.1.2.1.2.2.1.14.1)" value={form.oid} onChange={(e) => set('oid', e.target.value)} />
            <div className="flex gap-2">
                <select className={`${field} min-w-0 flex-1`} value={form.mode ?? 'get'} onChange={(e) => set('mode', e.target.value as SensorInput['mode'])}>
                    <option value="get">Read a single value (GET)</option>
                    <option value="walk">Walk a table + reduce</option>
                </select>
                {form.mode === 'walk' && (
                    <select className={`${field} min-w-0 flex-1`} value={form.agg ?? 'sum'} onChange={(e) => set('agg', e.target.value as SensorInput['agg'])}>
                        <option value="sum">Sum</option>
                        <option value="avg">Average</option>
                        <option value="max">Max</option>
                        <option value="min">Min</option>
                        <option value="count">Count rows</option>
                    </select>
                )}
            </div>
            <div className="flex gap-2">
                <input className={field} placeholder="Unit (e.g. errs, V, °C)" value={form.unit ?? ''} onChange={(e) => set('unit', e.target.value)} />
                <label className="flex shrink-0 items-center gap-2 text-sm text-white/60">
                    <span className="whitespace-nowrap">÷ by</span>
                    <input
                        type="number"
                        step="any"
                        value={form.divisor ?? 1}
                        onChange={(e) => set('divisor', Number(e.target.value))}
                        className="w-24 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                    />
                </label>
            </div>
            <ScopeEditor scope={form.scope ?? { type: 'all' }} onChange={(s) => set('scope', s)} />
            <label className="flex items-center gap-2 px-1 text-sm text-white/70">
                <input type="checkbox" checked={form.enabled ?? true} onChange={(e) => set('enabled', e.target.checked)} /> Enabled
            </label>
            <p className="px-1 text-[11px] text-white/35">
                Polled over SNMP on the metrics cadence for the devices in scope. The raw value is divided by the
                divisor (use it to scale, e.g. tenths of a volt → volts).
            </p>
            <div className="flex items-center justify-end gap-2 pt-1">
                <button onClick={onDone} className="rounded-full px-3 py-1.5 text-sm text-white/55 hover:text-white/90">Cancel</button>
                <button
                    onClick={submit}
                    disabled={save.isPending || form.name === '' || form.oid === ''}
                    className="rounded-full bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {save.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

export function SensorsSection() {
    const { data: sensors } = useSensors();
    const del = useDeleteSensor();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <section className={card}>
            <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-1.5 text-sm font-bold text-white">
                    <Broadcast weight="light" className="h-4 w-4 text-white/50" /> Custom SNMP sensors
                </h2>
                {!adding && (
                    <button onClick={() => { setAdding(true); setEditing(null); }} className="flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 hover:text-white">
                        <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                    </button>
                )}
            </div>

            {adding && <div className="mb-3"><SensorForm onDone={() => setAdding(false)} /></div>}

            {!sensors || sensors.length === 0 ? (
                <p className="text-xs text-white/40">No custom sensors. Add an OID to graph anything your gear exposes.</p>
            ) : (
                <div className="space-y-1.5">
                    {sensors.map((s) =>
                        editing === s.id ? (
                            <SensorForm key={s.id} initial={s} onDone={() => setEditing(null)} />
                        ) : (
                            <div key={s.id} className="flex items-center gap-2 rounded-xl px-3 py-2 text-xs ring-1 ring-white/[0.06]">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate font-medium text-white/85">{s.name}</span>
                                        {!s.enabled && <span className="shrink-0 text-[10px] text-white/30">disabled</span>}
                                    </div>
                                    <div className="mt-0.5 truncate font-mono text-white/40">{s.oid}{s.unit ? ` · ${s.unit}` : ''} · {scopeSummary(s.scope)}</div>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    <button onClick={() => { setEditing(s.id); setAdding(false); }} className="rounded-lg p-1 text-white/40 hover:bg-white/5 hover:text-white/80"><PencilSimple weight="bold" className="h-3.5 w-3.5" /></button>
                                    <button onClick={() => del.mutate(s.id)} className="rounded-lg p-1 text-white/40 hover:bg-white/5 hover:text-rose-400"><Trash weight="bold" className="h-3.5 w-3.5" /></button>
                                </div>
                            </div>
                        ),
                    )}
                </div>
            )}
        </section>
    );
}
