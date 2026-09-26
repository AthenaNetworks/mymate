/**
 * Which geo-map layers are on, remembered across reloads. Shared by every geo renderer so a
 * preference set in one survives a config change to another: `sites` is the map's content
 * markers (device pins in the Leaflet view; site markers in renderers whose unit is the site),
 * `backhauls` the site-to-site lines, `weather` an optional radar overlay a renderer may offer.
 *
 * Sites and backhauls are the map's actual content, so they start on; weather is context an
 * operator asks for, and it costs a third-party fetch, so it starts off.
 *
 * The per-map geo mode (GeoFlow) draws real device links, and planning wants to peel those back
 * (GitHub #22): `links` is the link lines themselves, `bandwidth` their load/capacity labels and
 * `ospf` the per-end OSPF cost badges. All three start on, same as the logical map.
 */
export type LayerPrefs = { sites: boolean; backhauls: boolean; weather: boolean; links: boolean; bandwidth: boolean; ospf: boolean };

const LAYER_PREFS_KEY = 'mymate.geo.layers';

export const LAYER_DEFAULTS: LayerPrefs = { sites: true, backhauls: true, weather: false, links: true, bandwidth: true, ospf: true };

export function loadLayerPrefs(): LayerPrefs {
    if (typeof window === 'undefined') return LAYER_DEFAULTS;
    try {
        const raw = window.localStorage.getItem(LAYER_PREFS_KEY);
        const saved = raw ? (JSON.parse(raw) as Partial<LayerPrefs>) : {};
        const pick = (k: keyof LayerPrefs): boolean => (typeof saved[k] === 'boolean' ? (saved[k] as boolean) : LAYER_DEFAULTS[k]);
        return {
            sites: pick('sites'),
            backhauls: pick('backhauls'),
            weather: pick('weather'),
            links: pick('links'),
            bandwidth: pick('bandwidth'),
            ospf: pick('ospf'),
        };
    } catch {
        return LAYER_DEFAULTS; // storage disabled or corrupt - fall back, never blank the map
    }
}

export function persistLayerPrefs(prefs: LayerPrefs): void {
    try {
        window.localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify(prefs));
    } catch {
        /* storage disabled or over quota - the toggles still work for this session */
    }
}

/** One layer pill. Emerald when the layer is on, glass when it's off. */
export function LayerToggle({ label, on, onClick, title }: { label: string; on: boolean; onClick: () => void; title: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            aria-pressed={on}
            className={`rounded-lg px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 backdrop-blur transition-colors ${
                on
                    ? 'bg-emerald-500/20 text-emerald-200 ring-emerald-400/40'
                    : 'bg-black/50 text-white/55 ring-white/15 hover:bg-black/70 hover:text-white/80'
            }`}
        >
            {label}
        </button>
    );
}
