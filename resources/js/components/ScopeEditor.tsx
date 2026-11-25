import { useMemo, useState } from 'react';
import { CaretDown, CaretRight, X } from '@phosphor-icons/react';
import { useDebounced, useDeviceList, useDevicesByIds } from '../features/devices/api/getDevices';
import { useMaps } from '../features/maps/api/maps';
import { useScopePreview } from '../features/alerts/api/scopePreview';
import { SCOPE_DEVICE_TYPES, toCombination, type FullScope } from '../lib/scope';
import type { AlertScope, DeviceType, NetworkMap, ScopeRule } from '../types';

const RESULTS = 50;

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';
const box = 'max-h-40 space-y-0.5 overflow-y-auto rounded-lg bg-black/20 p-1 ring-1 ring-white/[0.06]';
const check = 'h-3.5 w-3.5 rounded border-white/20 bg-white/[0.05] text-emerald-500 focus:ring-emerald-400/60';
const label = 'text-[11px] font-medium uppercase tracking-[0.14em] text-white/35';

type Rule = Required<ScopeRule>;

/**
 * Targeting editor shared by alert policies, maintenance windows and custom sensors. Include
 * devices by type (any of them), by map (any of them, optionally with their sub-maps) - both
 * together means a device must match each - plus any devices listed by name; then take out
 * anything matching the exclusions. Nothing chosen means every device. A live "Matches N devices"
 * line shows what the scope covers. Always writes the combination shape (App\Support\DeviceScope);
 * an older single-choice scope is converted with the same meaning when it's opened.
 */
export function ScopeEditor({ scope, onChange }: { scope: AlertScope; onChange: (s: AlertScope) => void }) {
    const { data: maps } = useMaps();
    const full = toCombination(scope);
    const hasExclusions = full.exclude.device_types.length + full.exclude.map_ids.length + full.exclude.device_ids.length > 0;
    const [showExclude, setShowExclude] = useState(hasExclusions);
    const { data: preview } = useScopePreview(full);

    const setSide = (side: keyof FullScope, patch: Partial<Rule>) => onChange({ ...full, [side]: { ...full[side], ...patch } });

    return (
        <div className="space-y-3 rounded-xl bg-white/[0.02] p-2.5 ring-1 ring-white/[0.06]">
            <div className="space-y-2">
                <div className="flex items-baseline justify-between gap-2">
                    <span className="text-sm text-white/70">Applies to</span>
                    <span className="text-[11px] text-white/35">Leave everything empty for all devices</span>
                </div>
                <RuleEditor rule={full.include} maps={maps} onChange={(p) => setSide('include', p)} verb="include" />
            </div>

            <div className="border-t border-white/[0.06] pt-2">
                <button
                    type="button"
                    onClick={() => setShowExclude((s) => !s)}
                    className="flex items-center gap-1.5 text-sm text-white/70 hover:text-white"
                    aria-expanded={showExclude || hasExclusions}
                >
                    {showExclude || hasExclusions ? <CaretDown className="h-3 w-3" /> : <CaretRight className="h-3 w-3" />}
                    Except...
                    {hasExclusions && <span className="text-[11px] text-amber-300/80">exclusions set</span>}
                </button>
                {(showExclude || hasExclusions) && (
                    <div className="mt-2 space-y-2">
                        <p className="text-[11px] text-white/35">A device matching any of these is left out, even if it's included above.</p>
                        <RuleEditor rule={full.exclude} maps={maps} onChange={(p) => setSide('exclude', p)} verb="exclude" />
                    </div>
                )}
            </div>

            <PreviewLine preview={preview} />
        </div>
    );
}

/** Types, maps (with the sub-maps switch) and listed devices for one side. */
function RuleEditor({ rule, maps, onChange, verb }: { rule: Rule; maps: NetworkMap[] | undefined; onChange: (p: Partial<Rule>) => void; verb: 'include' | 'exclude' }) {
    const toggleType = (t: DeviceType) =>
        onChange({ device_types: rule.device_types.includes(t) ? rule.device_types.filter((x) => x !== t) : [...rule.device_types, t] });

    return (
        <div className="space-y-2">
            <div className="space-y-1">
                <p className={label}>Device types {verb === 'include' && <span className="normal-case tracking-normal text-white/25">- any of them</span>}</p>
                <div className="flex flex-wrap gap-1">
                    {SCOPE_DEVICE_TYPES.map((t) => {
                        const on = rule.device_types.includes(t.value);
                        return (
                            <button
                                key={t.value}
                                type="button"
                                onClick={() => toggleType(t.value)}
                                aria-pressed={on}
                                className={`rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 transition-colors ${
                                    on
                                        ? verb === 'include'
                                            ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/30'
                                            : 'bg-rose-500/15 text-rose-300 ring-rose-400/30'
                                        : 'text-white/45 ring-white/10 hover:text-white/75'
                                }`}
                            >
                                {t.label}
                            </button>
                        );
                    })}
                </div>
            </div>

            <MapPicker
                maps={maps}
                value={rule.map_ids}
                submaps={rule.submaps}
                onChange={(map_ids) => onChange({ map_ids })}
                onSubmaps={(submaps) => onChange({ submaps })}
                hint={verb === 'include' && rule.device_types.length > 0 ? 'and on any of these maps' : undefined}
            />

            <DevicePicker value={rule.device_ids} onChange={(device_ids) => onChange({ device_ids })} title={verb === 'include' ? 'Also these devices' : 'Devices'} />
        </div>
    );
}

/** Maps in tree order (parents before their sub-maps), indented by depth. */
function treeOrder(maps: NetworkMap[]): { map: NetworkMap; depth: number }[] {
    const byParent = new Map<number | null, NetworkMap[]>();
    for (const m of maps) {
        const key = m.parent_map_id !== null && maps.some((p) => p.id === m.parent_map_id) ? m.parent_map_id : null;
        byParent.set(key, [...(byParent.get(key) ?? []), m]);
    }
    const sort = (list: NetworkMap[]) => [...list].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));
    const out: { map: NetworkMap; depth: number }[] = [];
    const seen = new Set<number>();
    const walk = (parent: number | null, depth: number) => {
        for (const m of sort(byParent.get(parent) ?? [])) {
            if (seen.has(m.id)) continue;
            seen.add(m.id);
            out.push({ map: m, depth });
            walk(m.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
}

function MapPicker({
    maps,
    value,
    submaps,
    onChange,
    onSubmaps,
    hint,
}: {
    maps: NetworkMap[] | undefined;
    value: number[];
    submaps: boolean;
    onChange: (ids: number[]) => void;
    onSubmaps: (on: boolean) => void;
    hint?: string;
}) {
    const [q, setQ] = useState('');
    const ordered = useMemo(() => treeOrder(maps ?? []), [maps]);
    const needle = q.trim().toLowerCase();
    const shown = needle ? ordered.filter(({ map }) => map.name.toLowerCase().includes(needle)) : ordered;
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id]);
    const anyWithSubmaps = value.some((id) => (maps ?? []).some((m) => m.parent_map_id === id));

    return (
        <div className="space-y-1">
            <div className="flex items-baseline justify-between gap-2">
                <p className={label}>
                    Maps {hint && <span className="normal-case tracking-normal text-white/25">- {hint}</span>}
                </p>
                <span className="text-[11px] text-white/35">{value.length > 0 ? `${value.length} selected` : 'any'}</span>
            </div>
            {ordered.length > 8 && <input className={field} placeholder="Find a map..." value={q} onChange={(e) => setQ(e.target.value)} />}
            <div className={box}>
                {shown.map(({ map, depth }) => (
                    <label
                        key={map.id}
                        className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm text-white/75 hover:bg-white/[0.04]"
                        style={{ paddingLeft: `${0.5 + (needle ? 0 : depth) * 0.9}rem` }}
                    >
                        <input type="checkbox" checked={value.includes(map.id)} onChange={() => toggle(map.id)} className={check} />
                        <span className="min-w-0 flex-1 truncate">{map.name}</span>
                        <span className="shrink-0 text-[11px] tabular-nums text-white/30">{map.device_count}</span>
                    </label>
                ))}
                {shown.length === 0 && <p className="px-2 py-1 text-xs text-white/35">No maps match.</p>}
            </div>
            {value.length > 0 && (
                <label className="flex cursor-pointer items-center gap-2 px-1 text-xs text-white/60" title="Also the devices on every map under the selected ones">
                    <input type="checkbox" checked={submaps} onChange={(e) => onSubmaps(e.target.checked)} className={check} />
                    Include sub-maps
                    {!anyWithSubmaps && <span className="text-white/30">(none of these has any yet)</span>}
                </label>
            )}
        </div>
    );
}

/** Devices listed by name: the chosen ones as removable chips, and a search to add more. */
function DevicePicker({ value, onChange, title }: { value: number[]; onChange: (ids: number[]) => void; title: string }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const query = useDebounced(q.trim());
    // Server-side search (GitHub #22), only while the search is open.
    const { data: page } = useDeviceList({ q: query || undefined, per_page: RESULTS, fields: 'summary' }, { enabled: open });
    const { data: chosen } = useDevicesByIds(value);
    const nameOf = (id: number) => chosen?.find((d) => d.id === id)?.name ?? `device ${id}`;
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id]);
    const more = (page?.meta.total ?? 0) > RESULTS;

    return (
        <div className="space-y-1">
            <div className="flex items-baseline justify-between gap-2">
                <p className={label}>{title}</p>
                <button type="button" onClick={() => setOpen((o) => !o)} className="text-[11px] text-emerald-300/80 hover:text-emerald-200">
                    {open ? 'Done' : value.length > 0 ? 'Edit' : 'Add devices'}
                </button>
            </div>
            {value.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {value.map((id) => (
                        <span key={id} className="flex items-center gap-1 rounded-full bg-white/[0.05] py-0.5 pl-2 pr-1 text-[11px] text-white/75 ring-1 ring-white/10">
                            {nameOf(id)}
                            <button type="button" onClick={() => toggle(id)} aria-label={`Remove ${nameOf(id)}`} className="rounded-full p-0.5 text-white/40 hover:bg-white/10 hover:text-white">
                                <X className="h-2.5 w-2.5" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            {open && (
                <div className="space-y-1.5">
                    <input className={field} placeholder="Search devices..." value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
                    <div className={box}>
                        {(page?.data ?? []).map((d) => (
                            <label key={d.id} className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm text-white/75 hover:bg-white/[0.04]">
                                <input type="checkbox" checked={value.includes(d.id)} onChange={() => toggle(d.id)} className={check} />
                                <span className="min-w-0 flex-1 truncate">{d.name}</span>
                                <span className="shrink-0 font-mono text-[11px] text-white/30">{d.mgmt_ip}</span>
                            </label>
                        ))}
                        {page && page.data.length === 0 && <p className="px-2 py-1 text-xs text-white/35">No devices match.</p>}
                    </div>
                    {more && <p className="px-1 text-[11px] text-white/35">Showing the first {RESULTS} - search to narrow it down.</p>}
                </div>
            )}
        </div>
    );
}

function PreviewLine({ preview }: { preview: ReturnType<typeof useScopePreview>['data'] }) {
    if (!preview) return <p className="px-1 text-[11px] text-white/30">Working out which devices this covers...</p>;
    if (preview.all) return <p className="px-1 text-xs text-white/55">Covers all {preview.total} devices.</p>;
    if (preview.count === 0) return <p className="px-1 text-xs text-amber-300/80">Matches no devices.</p>;
    const names = preview.sample.map((d) => d.name).join(', ');
    return (
        <p className="px-1 text-xs text-white/55" title={names}>
            Matches <span className="font-semibold text-white/80">{preview.count}</span> of {preview.total} devices
            <span className="text-white/35">
                {' '}
                - {names}
                {preview.count > preview.sample.length ? ', ...' : ''}
            </span>
        </p>
    );
}
