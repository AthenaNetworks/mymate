import { CaretRight } from '@phosphor-icons/react';
import { useMaps } from '../api/maps';
import { useActiveMapId, setActiveMap } from '../../../lib/shellStore';

/**
 * Ancestor trail for the current map (GitHub #9) - when you've drilled into a sub-map, this
 * shows its parents so you can jump back up. Renders nothing for a top-level map.
 */
export function MapBreadcrumb() {
    const { data: maps } = useMaps();
    const activeId = useActiveMapId();
    if (!maps || activeId === null) return null;

    const byId = new Map(maps.map((m) => [m.id, m]));
    // Walk up from the current map's parent, collecting ancestors nearest-first, then reverse
    // so the trail reads top-down. Guard against a cycle.
    const trail: { id: number; name: string }[] = [];
    const seen = new Set<number>();
    let cur = byId.get(activeId)?.parent_map_id ?? null;
    while (cur !== null && !seen.has(cur)) {
        seen.add(cur);
        const m = byId.get(cur);
        if (!m) break;
        trail.push({ id: m.id, name: m.name });
        cur = m.parent_map_id;
    }
    if (trail.length === 0) return null;
    trail.reverse();

    return (
        <div className="flex items-center gap-0.5 rounded-full bg-[#0d0d11]/80 px-2 py-1.5 text-[11px] ring-1 ring-white/10 backdrop-blur-xl">
            {trail.map((m) => (
                <span key={m.id} className="flex items-center gap-0.5">
                    <button
                        onClick={() => setActiveMap(m.id)}
                        className="max-w-[9rem] truncate rounded px-1.5 py-0.5 text-white/55 transition-colors hover:bg-white/5 hover:text-white/90"
                        title={`Back to ${m.name}`}
                    >
                        {m.name}
                    </button>
                    <CaretRight weight="bold" className="h-3 w-3 shrink-0 text-white/25" />
                </span>
            ))}
        </div>
    );
}
