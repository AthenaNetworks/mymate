import { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { LinkSimple, Pulse, X } from '@phosphor-icons/react';
import { useInterfaceSamples } from '../api/getInterfaceSamples';
import { useUpdateLink } from '../api/updateLink';
import { InterfaceChart } from './InterfaceChart';
import { EndPicker } from './LinkBinderDialog';
import { MediaTypePicker } from './MediaTypePicker';
import { useIsAdmin } from '../../auth/api/auth';
import { pushToast } from '../../../lib/toast';
import { formatRate } from '../../../lib/formatRate';
import type { ChartMode } from '../../../lib/shellStore';
import type { Device, InterfaceSample, Link, LinkMediaType } from '../../../types';

const WINDOWS = [
    ['1h', 3600],
    ['6h', 21600],
    ['24h', 86400],
] as const;

/** "500M" / "1.5G" for an Mbps figure. */
function speedLabel(mbps: number | null): string {
    if (!mbps) return '-';
    return mbps >= 1000 ? `${(mbps / 1000).toFixed(mbps % 1000 === 0 ? 0 : 1)}G` : `${mbps}M`;
}

const utilPct = (bps: number | null, effMbps: number | null): number | null =>
    bps !== null && effMbps ? (bps / (effMbps * 1_000_000)) * 100 : null;

/**
 * Fold the link\'s two end-interface series into ONE link series. A
 * link\'s two ends mirror each other, so we take the busier reading per direction per
 * time bucket: A->B = max(A.out, B.in) -> the "in" slot; B->A = max(B.out, A.in) -> "out".
 * Utilisation is recomputed from the *link* effective speed, not the per-port
 * util the samples were stored with.
 */
function mergeLinkSamples(
    a: InterfaceSample[],
    b: InterfaceSample[],
    effAb: number | null,
    effBa: number | null,
): InterfaceSample[] {
    const byTs = new Map<string, { ab: number | null; ba: number | null }>();
    const put = (ts: string, ab: number | null, ba: number | null) => {
        const cur = byTs.get(ts) ?? { ab: null, ba: null };
        if (ab !== null) cur.ab = Math.max(cur.ab ?? 0, ab);
        if (ba !== null) cur.ba = Math.max(cur.ba ?? 0, ba);
        byTs.set(ts, cur);
    };
    for (const s of a) put(s.ts, s.bps_out, s.bps_in); // A end: out = A->B, in = B->A
    for (const s of b) put(s.ts, s.bps_in, s.bps_out); // B end: in = A->B, out = B->A

    return [...byTs.entries()]
        .sort(([t1], [t2]) => t1.localeCompare(t2))
        .map(([ts, { ab, ba }]) => ({
            ts,
            bps_in: ab,
            bps_out: ba,
            util_in: utilPct(ab, effAb),
            util_out: utilPct(ba, effBa),
        }));
}

function LinkLegend({ toLabel, fromLabel }: { toLabel: string; fromLabel: string }) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-white/45">
            <span className="flex min-w-0 items-center gap-1.5">
                <span className="h-1.5 w-3 shrink-0 rounded-full bg-emerald-400" /> <span className="truncate">→ {toLabel}</span>
            </span>
            <span className="flex min-w-0 items-center gap-1.5">
                <span className="h-1.5 w-3 shrink-0 rounded-full bg-sky-400" /> <span className="truncate">→ {fromLabel}</span>
            </span>
        </div>
    );
}

/** One chart for the whole link: throughput by default, util% when the link speed is known. */
function LinkHistory({ link, aName, bName, windowSec }: { link: Link; aName: string; bName: string; windowSec: number }) {
    const aq = useInterfaceSamples(link.a_interface_id, windowSec);
    const bq = useInterfaceSamples(link.b_interface_id, windowSec);
    const loading = aq.isLoading || bq.isLoading;
    const hasSpeed = link.eff_ab_mbps != null || link.eff_ba_mbps != null;
    // Default to throughput - always meaningful; util% only makes sense with a known speed.
    const [mode, setMode] = useState<ChartMode>('rate');

    const merged = useMemo(
        () => mergeLinkSamples(aq.data ?? [], bq.data ?? [], link.eff_ab_mbps, link.eff_ba_mbps),
        [aq.data, bq.data, link.eff_ab_mbps, link.eff_ba_mbps],
    );

    const last = merged.length > 0 ? merged[merged.length - 1] : null;
    const showUtil = hasSpeed && mode === 'util';
    const nowLabel = !last
        ? '-'
        : showUtil
          ? `${Math.max(last.util_in ?? 0, last.util_out ?? 0).toFixed(Math.max(last.util_in ?? 0, last.util_out ?? 0) < 10 ? 2 : 1)}%`
          : formatRate(Math.max(last.bps_in ?? 0, last.bps_out ?? 0));

    return (
        <section className="rounded-2xl bg-white/[0.02] p-3 ring-1 ring-white/[0.06]">
            <header className="mb-1 flex items-center justify-between px-1">
                <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-white/85">Link throughput</span>
                    <span className="block truncate text-[11px] text-white/35">
                        {hasSpeed
                            ? `effective ${speedLabel(link.eff_ab_mbps)}dn / ${speedLabel(link.eff_ba_mbps)}up`
                            : 'no link speed set - set a bandwidth override in Edit for util %'}
                    </span>
                </span>
                <span className="shrink-0 text-right">
                    <span className="block text-sm font-semibold tabular-nums text-white/90">{nowLabel}</span>
                    <span className="block text-[10px] uppercase tracking-wide text-white/30">now</span>
                </span>
            </header>
            <InterfaceChart samples={merged} loading={loading} mode={mode} onModeChange={setMode} hasSpeed={hasSpeed} />
            <div className="mt-1 px-1">
                <LinkLegend toLabel={bName} fromLabel={aName} />
            </div>
        </section>
    );
}

/** A nullable Mbps override input for one link direction. */
function BwInput({
    label,
    value,
    placeholder,
    onChange,
}: {
    label: string;
    value: string;
    placeholder: string;
    onChange: (v: string) => void;
}) {
    return (
        <label className="flex items-center justify-between gap-3 text-xs text-white/60">
            <span className="min-w-0 truncate">{label}</span>
            <span className="flex shrink-0 items-center gap-1.5">
                <input
                    type="number"
                    min={1}
                    value={value}
                    placeholder={placeholder}
                    onChange={(e) => onChange(e.target.value)}
                    className="w-20 rounded-lg bg-white/[0.04] px-2 py-1 text-right font-mono text-xs text-white ring-1 ring-white/10 transition-colors duration-200 ease-fluid focus:ring-emerald-400/50 focus:outline-none"
                />
                <span className="text-[10px] text-white/30">Mbps</span>
            </span>
        </label>
    );
}

/** Re-bind either end of an existing link + set the per-link bandwidth override. Reuses
 *  the binder\'s EndPicker + the update API. */
function EditPanel({ link, devices, onSaved }: { link: Link; devices: Device[]; onSaved: () => void }) {
    const aDev = devices.find((d) => d.id === link.a_device_id);
    const bDev = devices.find((d) => d.id === link.b_device_id);
    const aName = aDev?.name ?? `device ${link.a_device_id}`;
    const bName = bDev?.name ?? `device ${link.b_device_id}`;
    const [aIf, setAIf] = useState(link.a_interface_id != null ? String(link.a_interface_id) : '');
    const [bIf, setBIf] = useState(link.b_interface_id != null ? String(link.b_interface_id) : '');
    const [bwAb, setBwAb] = useState(link.bw_ab_mbps != null ? String(link.bw_ab_mbps) : '');
    const [bwBa, setBwBa] = useState(link.bw_ba_mbps != null ? String(link.bw_ba_mbps) : '');
    const [media, setMedia] = useState<LinkMediaType | null>(link.media_type);
    const update = useUpdateLink();
    // A ping-only end has no interface to pick, so it's ready without a selection.
    const aPingOnly = aDev?.poll_method === 'none';
    const bPingOnly = bDev?.poll_method === 'none';
    const ready = (aPingOnly || aIf !== '') && (bPingOnly || bIf !== '') && !update.isPending;

    const parse = (v: string): number | null => {
        const n = Number(v.trim());
        return v.trim() !== '' && Number.isInteger(n) && n >= 1 ? n : null;
    };

    function save() {
        if (!ready) return;
        update.mutate(
            {
                id: link.id,
                a_device_id: link.a_device_id,
                a_interface_id: aPingOnly ? null : Number(aIf),
                b_device_id: link.b_device_id,
                b_interface_id: bPingOnly ? null : Number(bIf),
                bw_ab_mbps: parse(bwAb),
                bw_ba_mbps: parse(bwBa),
                media_type: media,
            },
            {
                onSuccess: () => {
                    pushToast({ title: 'Link updated', tone: 'info' });
                    onSaved();
                },
            },
        );
    }

    return (
        <div className="space-y-3.5">
            <EndPicker device={aDev} value={aIf} onChange={setAIf} />
            <EndPicker device={bDev} value={bIf} onChange={setBIf} />

            <MediaTypePicker value={media} onChange={setMedia} />

            <div className="space-y-2 rounded-2xl bg-white/[0.02] p-3 ring-1 ring-white/[0.06]">
                <p className="text-[11px] font-medium uppercase tracking-wide text-white/35">Bandwidth override</p>
                <p className="text-[11px] text-white/40">
                    Blank = derive from the slower end ({speedLabel(link.eff_ab_mbps)}). Set per direction for asymmetric circuits.
                </p>
                <BwInput label={`${aName} -> ${bName}`} value={bwAb} placeholder={speedLabel(link.eff_ab_mbps)} onChange={setBwAb} />
                <BwInput label={`${bName} -> ${aName}`} value={bwBa} placeholder={speedLabel(link.eff_ba_mbps)} onChange={setBwBa} />
            </div>

            {update.isError && (
                <p className="text-xs text-rose-400/90">
                    Couldn't update the link - they may already be linked, or an interface doesn't match its device.
                </p>
            )}

            <div className="flex items-center justify-end pt-1">
                <button
                    onClick={save}
                    disabled={!ready}
                    className="group flex items-center gap-2 rounded-full bg-emerald-500 py-2 pl-5 pr-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    <span>{update.isPending ? 'Saving...' : 'Save changes'}</span>
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5">
                        <LinkSimple weight="bold" className="h-4 w-4" />
                    </span>
                </button>
            </div>
        </div>
    );
}

export function LinkHistoryDialog({
    link,
    devices,
    onClose,
    defaultTab = 'history',
}: {
    link: Link;
    devices: Device[];
    onClose: () => void;
    defaultTab?: 'history' | 'edit';
}) {
    const isAdmin = useIsAdmin();
    const [windowSec, setWindowSec] = useState<number>(3600);
    // Read-only viewers only ever see the history chart - the Edit tab is a write tool.
    const [tab, setTab] = useState<'history' | 'edit'>(isAdmin ? defaultTab : 'history');
    const nameOf = (id: number) => devices.find((d) => d.id === id)?.name ?? `device ${id}`;
    const aName = nameOf(link.a_device_id);
    const bName = nameOf(link.b_device_id);

    const tabBtn = (active: boolean) =>
        'rounded-full px-2.5 py-0.5 transition-colors duration-300 ease-fluid ' +
        (active ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70');

    // Portal to <body>: the device inspector pane is a transform/backdrop-filter ancestor, which
    // would otherwise become the containing block for this `fixed` overlay and trap it inside the
    // narrow pane instead of covering the viewport (GitHub #39).
    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            {/* Backdrop - a fixed element, so glass blur is allowed here (never on the canvas). */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-xl rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="relative max-h-[85vh] overflow-y-auto rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    {/* Close - pinned to the dialog\'s top-right corner, separate from the
                        tab/time controls so it always reads as "close the dialog". */}
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="absolute right-4 top-4 z-10 rounded-lg p-1 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                    <header className="mb-4 flex flex-wrap items-start justify-between gap-2 pr-10">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <Pulse weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">
                                    {tab === 'history' ? 'Link history' : 'Edit link'}
                                </h2>
                                <p className="text-xs text-white/40">
                                    {aName} - {bName}
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {/* History / Edit tabs - single-click friendly (no double-click).
                                Edit is a write tool, so it\'s hidden from read-only viewers. */}
                            {isAdmin && (
                                <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                                    <button onClick={() => setTab('history')} className={tabBtn(tab === 'history')}>
                                        History
                                    </button>
                                    <button onClick={() => setTab('edit')} className={tabBtn(tab === 'edit')}>
                                        Edit
                                    </button>
                                </div>
                            )}
                            {tab === 'history' && (
                                <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                                    {WINDOWS.map(([label, secs]) => (
                                        <button key={label} onClick={() => setWindowSec(secs)} className={tabBtn(windowSec === secs)}>
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </header>

                    {tab === 'history' ? (
                        <LinkHistory link={link} aName={aName} bName={bName} windowSec={windowSec} />
                    ) : (
                        <EditPanel link={link} devices={devices} onSaved={() => setTab('history')} />
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}
