/**
 * Which geo-map layers are on, remembered across reloads. Shared by every geo renderer so a
 * preference set in one survives a config change to another: `sites` is the map's content
 * markers (device pins in the Leaflet view; site markers in renderers whose unit is the site),
 * `backhauls` the site-to-site lines, `weather` an optional radar overlay a renderer may offer.
 *
 * Sites and backhauls are the map's actual content, so they start on; weather is context an
 * operator asks for, and it costs a third-party fetch, so it starts off.
 */
export type LayerPrefs = { sites: boolean; backhauls: boolean; weather: boolean };

const LAYER_PREFS_KEY = 'mymate.geo.layers';

export const LAYER_DEFAULTS: LayerPrefs = { sites: true, backhauls: true, weather: false };

export function loadLayerPrefs(): LayerPrefs {
    if (typeof window === 'undefined') return LAYER_DEFAULTS;
    try {
        const raw = window.localStorage.getItem(LAYER_PREFS_KEY);
        const saved = raw ? (JSON.parse(raw) as Partial<LayerPrefs>) : {};
        return {
            sites: typeof saved.sites === 'boolean' ? saved.sites : LAYER_DEFAULTS.sites,
            backhauls: typeof saved.backhauls === 'boolean' ? saved.backhauls : LAYER_DEFAULTS.backhauls,
            weather: typeof saved.weather === 'boolean' ? saved.weather : LAYER_DEFAULTS.weather,
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
