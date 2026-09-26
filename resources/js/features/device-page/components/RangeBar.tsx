import { useState } from 'react';
import { ArrowCounterClockwise, ArrowUUpLeft, CalendarBlank } from '@phosphor-icons/react';
import { PRESETS, type GraphRangeControl } from '../lib/range';
import { fmtStamp } from '../lib/format';

const chip = 'rounded-md px-2 py-1 text-xs font-medium transition-colors duration-200 ease-fluid';
const field = 'rounded-lg bg-white/[0.04] px-2 py-1 text-xs text-white/85 outline-none ring-1 ring-white/10 focus:ring-emerald-400/50';

/** datetime-local wants local wall time without a zone. */
function toLocalInput(sec: number): string {
    const d = new Date(sec * 1000);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/**
 * Range controls shared by every graph on the tab: presets, a custom from/to, back (undo the
 * last zoom), reset, and the previous-period overlay. Drag across any graph to zoom.
 */
export function RangeBar({ control, maxDays }: { control: GraphRangeControl; maxDays?: number }) {
    const { range, compare } = control;
    const [custom, setCustom] = useState(false);
    const [from, setFrom] = useState(() => toLocalInput(range.from));
    const [to, setTo] = useState(() => toLocalInput(range.to));
    const presets = PRESETS.filter(([, s]) => !maxDays || s <= (maxDays + 1) * 86400);

    function applyCustom() {
        const a = Date.parse(from) / 1000;
        const b = Date.parse(to) / 1000;
        if (Number.isFinite(a) && Number.isFinite(b) && b > a) {
            control.setWindow(a, b);
            setCustom(false);
        }
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="flex flex-wrap items-center gap-0.5 rounded-lg bg-white/[0.04] p-0.5 ring-1 ring-white/10">
                {presets.map(([k]) => (
                    <button
                        key={k}
                        type="button"
                        onClick={() => control.setPreset(k)}
                        className={`${chip} ${range.preset === k ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80'}`}
                    >
                        {k}
                    </button>
                ))}
                <button
                    type="button"
                    onClick={() => {
                        setFrom(toLocalInput(range.from));
                        setTo(toLocalInput(range.to));
                        setCustom((c) => !c);
                    }}
                    title="Pick a custom window"
                    className={`${chip} flex items-center gap-1 ${range.preset === null || custom ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80'}`}
                >
                    <CalendarBlank weight="bold" className="h-3.5 w-3.5" /> Custom
                </button>
            </div>

            <button type="button" onClick={control.back} disabled={!control.canGoBack} title="Back to the previous range" className={`${chip} flex items-center gap-1 text-white/55 ring-1 ring-white/10 hover:text-white disabled:opacity-30`}>
                <ArrowUUpLeft weight="bold" className="h-3.5 w-3.5" /> Back
            </button>
            <button type="button" onClick={control.reset} title="Back to the last 24 hours" className={`${chip} flex items-center gap-1 text-white/55 ring-1 ring-white/10 hover:text-white`}>
                <ArrowCounterClockwise weight="bold" className="h-3.5 w-3.5" /> Reset
            </button>

            <label className="flex cursor-pointer items-center gap-1.5 text-xs text-white/55" title="Overlay the same span just before this one as a faint dotted line">
                <input type="checkbox" checked={compare} onChange={(e) => control.setCompare(e.target.checked)} className="h-3.5 w-3.5 accent-emerald-400" />
                Compare to previous period
            </label>

            <span className="ml-auto font-mono text-[11px] text-white/35">
                {fmtStamp(range.from * 1000)} - {range.live ? 'now' : fmtStamp(range.to * 1000)}
            </span>

            {custom && (
                <div className="flex w-full flex-wrap items-center gap-2 rounded-xl bg-white/[0.03] p-2 ring-1 ring-white/[0.06]">
                    <span className="text-xs text-white/45">From</span>
                    <input type="datetime-local" value={from} onChange={(e) => setFrom(e.target.value)} className={field} />
                    <span className="text-xs text-white/45">to</span>
                    <input type="datetime-local" value={to} onChange={(e) => setTo(e.target.value)} className={field} />
                    <button type="button" onClick={applyCustom} className="rounded-lg bg-emerald-500/15 px-2.5 py-1 text-xs font-medium text-emerald-300 ring-1 ring-emerald-400/25 hover:bg-emerald-500/25">
                        Apply
                    </button>
                    <span className="text-[11px] text-white/30">Tip: drag across any graph to zoom in.</span>
                </div>
            )}
        </div>
    );
}
