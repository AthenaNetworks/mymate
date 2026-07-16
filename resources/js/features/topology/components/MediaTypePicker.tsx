import type { LinkMediaType } from '../../../types';
import { MEDIA_TYPES, MEDIA_META } from '../lib/mediaType';

/** Compact segmented picker for a link's physical medium (null = unspecified). */
export function MediaTypePicker({ value, onChange }: { value: LinkMediaType | null; onChange: (v: LinkMediaType | null) => void }) {
    return (
        <label className="block space-y-1.5">
            <span className="px-1 text-[11px] font-medium text-white/55">Media type</span>
            <div className="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    onClick={() => onChange(null)}
                    className={`rounded-lg px-2.5 py-1 text-xs ring-1 transition ${value === null ? 'bg-white/10 text-white ring-white/30' : 'text-white/55 ring-white/10 hover:bg-white/5'}`}
                >
                    None
                </button>
                {MEDIA_TYPES.map((m) => {
                    const meta = MEDIA_META[m];
                    const on = value === m;
                    return (
                        <button
                            key={m}
                            type="button"
                            onClick={() => onChange(m)}
                            className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs ring-1 transition ${on ? 'text-white ring-white/30' : 'text-white/55 ring-white/10 hover:bg-white/5'}`}
                            style={on ? { background: `${meta.color}22`, borderColor: meta.color } : undefined}
                        >
                            <span className="h-2 w-2 rounded-full" style={{ background: meta.color }} />
                            {meta.label}
                        </button>
                    );
                })}
            </div>
        </label>
    );
}
