import { useState } from 'react';
import { useOspfCostSize, setOspfCostSize, useOspfCostColor, setOspfCostColor, type OspfCostSize } from '../../../lib/shellStore';

// Size + colour for the OSPF cost badges on links (GitHub #22). Per-browser prefs, so each
// operator can tune the cost labels for their own planning view. Only rendered when the active
// map actually carries OSPF costs (see MapCanvas), so it never clutters a non-OSPF map.

const SIZES: { key: OspfCostSize; label: string }[] = [
    { key: 'sm', label: 'S' },
    { key: 'md', label: 'M' },
    { key: 'lg', label: 'L' },
];

// A small high-contrast palette (indigo default, then pink/amber/green/blue/white).
const COLORS = ['#a5b4fc', '#f9a8d4', '#fcd34d', '#6ee7b7', '#93c5fd', '#ffffff'];

export function OspfCostControl() {
    const size = useOspfCostSize();
    const color = useOspfCostColor();
    const [open, setOpen] = useState(false);

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((o) => !o)}
                title="OSPF cost label size and colour"
                className="flex items-center gap-1.5 rounded-full bg-white/5 px-3 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 backdrop-blur-xl transition-colors hover:bg-white/10 hover:text-white"
            >
                <span className="h-2.5 w-2.5 rounded-full ring-1 ring-white/20" style={{ backgroundColor: color }} />
                <span className="hidden sm:inline">OSPF</span>
            </button>
            {open && (
                <>
                    {/* Click-away. */}
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="animate-rise absolute right-0 top-full z-20 mt-2 w-44 rounded-2xl bg-[#0d0d11]/95 p-2.5 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-xl">
                        <p className="px-0.5 pb-1.5 text-[10px] uppercase tracking-wide text-white/30">OSPF cost label</p>
                        <div className="flex items-center gap-1">
                            {SIZES.map((s) => (
                                <button
                                    key={s.key}
                                    onClick={() => setOspfCostSize(s.key)}
                                    className={`flex-1 rounded-lg px-2 py-1 text-xs font-medium transition-colors ${
                                        size === s.key ? 'bg-white/10 text-emerald-300 ring-1 ring-white/15' : 'text-white/55 hover:bg-white/5 hover:text-white/80'
                                    }`}
                                >
                                    {s.label}
                                </button>
                            ))}
                        </div>
                        <div className="mt-2 flex items-center justify-between px-0.5">
                            {COLORS.map((c) => (
                                <button
                                    key={c}
                                    onClick={() => setOspfCostColor(c)}
                                    title={c}
                                    className={`h-5 w-5 rounded-full transition-transform hover:scale-110 ${
                                        color.toLowerCase() === c.toLowerCase() ? 'ring-2 ring-white/70' : 'ring-1 ring-white/15'
                                    }`}
                                    style={{ backgroundColor: c }}
                                />
                            ))}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
