import { useSyncExternalStore } from 'react';

/**
 * Tiny dependency-free shell store (same pattern as lib/toast.ts) for the NOC
 * console: which workspace view is active, and which device the inspector shows.
 * Lives in the shared core so the shell and the topology feature both read/write
 * it without a cross-feature import. No provider needed.
 */
export type View = 'map' | 'geo' | 'dashboard' | 'devices' | 'device' | 'discovery' | 'graphs' | 'tools' | 'outages' | 'alerts' | 'upgrades' | 'backups' | 'settings' | 'import';

/** Per-device inspector view prefs. */
export type ChartMode = 'util' | 'rate';
export type IfaceFilter = 'all' | 'linked' | 'traffic';
/** Which resource a device's map tile shows. Re-exported from types for store use. */
export type TileMetric = 'throughput' | 'cpu' | 'mem' | 'temp';

/** Map link geometry - curved bezier (default) or straight point-to-point. */
export type EdgeStyle = 'curved' | 'straight';

/**
 * How a link attaches to the two cards it joins. 'auto' floats each end to whichever side
 * faces the other card and re-picks as cards move (tidy by default); 'fixed' honours the side
 * an operator pinned the end to by dragging it there.
 */
export type EdgeAttach = 'auto' | 'fixed';

/** OSPF cost badge size on the map - remembered per-browser (GitHub #22 readability). */
export type OspfCostSize = 'sm' | 'md' | 'lg';

/** Map dot grid bounds + defaults. The size is also the snap grid, so the dots mark exactly
 *  where a dragged card can land. Colours are per theme - one grey can't suit both canvases. */
export const MAP_GRID_MIN = 4;
export const MAP_GRID_MAX = 128;
export const MAP_GRID_DEFAULT_SIZE = 16;
export const MAP_GRID_DEFAULT_COLOR = { dark: '#646464', light: '#646464' } as const;

/** Auto-layout algorithm - remembered per-browser; mirrors LayoutKind in lib/layout. */
export type LayoutKind = 'smart' | 'tree-tb' | 'tree-lr' | 'radial' | 'force' | 'dependency';

type ShellState = {
    view: View;
    // Transient off-canvas UI: the mobile nav drawer + inspector sheet.
    // NOT persisted (reset on reload) - unlike the prefs below.
    navOpen: boolean;
    inspectorOpen: boolean;
    selectedDeviceId: number | null;
    activeMapId: number | null;
    // Kept as flat primitive records (not a nested object per id) so each getSnapshot
    // returns a stable scalar - a fresh object per render would loop useSyncExternalStore.
    chartModeById: Record<number, ChartMode>;
    ifaceFilterById: Record<number, IfaceFilter>;
    tileMetricById: Record<number, TileMetric>; // which resource each map tile shows
    edgeStyle: EdgeStyle; // map link geometry - curved (default) or straight
    edgeAttach: EdgeAttach; // links float to the facing side ('auto') or stay on the pinned side ('fixed')
    ospfCostSize: OspfCostSize; // OSPF cost badge size on links
    ospfCostColor: string; // OSPF cost badge colour (hex)
    layoutKind: LayoutKind; // last-chosen auto-layout algorithm
    mapGrid: boolean; // draw the dot grid on the map
    mapGridSize: number; // dot spacing AND snap grid, in flow px
    mapGridColorDark: string; // dot colour on the dark theme (hex)
    mapGridColorLight: string; // dot colour on the light theme (hex)
    wallboard: boolean; // big-screen / TV presentation mode
    mapToolsCollapsed: boolean; // map tools panel folded to a slim rail on the right (lg+ only)
    mapToolsOrphansOnly: boolean; // map tools device list: only devices on no map at all
    // Dashboard: which devices the card grid shows + the auto-page interval.
    // `dashboardAll` is a mode flag (not a frozen id list) so new devices auto-appear.
    dashboardAll: boolean;
    dashboardIds: number[];
    dashboardCycleS: number;
};

// --- Page routing: the active view lives in the URL path so a
// reload / shared link / Back-Forward restores the page. No router lib - rides the
// server catch-all (GET /{any} -> app). Page only; map/device keep default-on-load.
const VIEW_TO_PATH: Record<View, string> = {
    map: '/',
    geo: '/geo',
    dashboard: '/dashboard',
    devices: '/devices',
    device: '/devices', // the real path carries the id, see DEVICE_PAGE
    discovery: '/discovery',
    graphs: '/graphs',
    tools: '/tools',
    outages: '/outages',
    alerts: '/alerts',
    upgrades: '/upgrades',
    backups: '/backups',
    settings: '/settings',
    import: '/import',
};

// The full device page (/devices/{id}, /devices/{id}/ports/{ifId}) carries ids in its path,
// so it's matched by pattern rather than looked up. The page reads the rest of the URL itself.
const DEVICE_PAGE = /^\/devices\/\d+(\/ports\/\d+)?$/;

function pathToView(pathname: string): View {
    const p = pathname.replace(/\/+$/, '').toLowerCase() || '/';
    if (DEVICE_PAGE.test(p)) return 'device';
    return (Object.keys(VIEW_TO_PATH) as View[]).find((v) => VIEW_TO_PATH[v] === p) ?? 'map';
}

// Derive the initial view from the URL at module load (before first render -> no flash).
const initialView: View = typeof window !== 'undefined' ? pathToView(window.location.pathname) : 'map';

// --- Persistence: remember the selected device, active map, and
// per-device inspector prefs across reloads (localStorage). The page lives in the URL
//, so it\'s not stored here. Secrets/util never touch storage.
const STORAGE_KEY = 'mymate.shell.v1';
type Persisted = Pick<
    ShellState,
    | 'selectedDeviceId'
    | 'activeMapId'
    | 'chartModeById'
    | 'ifaceFilterById'
    | 'tileMetricById'
    | 'edgeStyle'
    | 'edgeAttach'
    | 'ospfCostSize'
    | 'ospfCostColor'
    | 'layoutKind'
    | 'mapGrid'
    | 'mapGridSize'
    | 'mapGridColorDark'
    | 'mapGridColorLight'
    | 'wallboard'
    | 'mapToolsCollapsed'
    | 'mapToolsOrphansOnly'
    | 'dashboardAll'
    | 'dashboardIds'
    | 'dashboardCycleS'
>;

function loadPersisted(): Partial<Persisted> {
    if (typeof window === 'undefined') return {};
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        return raw ? (JSON.parse(raw) as Partial<Persisted>) : {};
    } catch {
        return {};
    }
}
const saved = loadPersisted();

/** Whole px within bounds; anything unusable (NaN, a hand-edited string) falls back to the default. */
export function clampGridSize(n: unknown): number {
    const v = typeof n === 'number' && Number.isFinite(n) ? Math.round(n) : MAP_GRID_DEFAULT_SIZE;
    return Math.min(MAP_GRID_MAX, Math.max(MAP_GRID_MIN, v));
}

let state: ShellState = {
    view: initialView,
    navOpen: false,
    inspectorOpen: false,
    selectedDeviceId: saved.selectedDeviceId ?? null,
    activeMapId: saved.activeMapId ?? null,
    chartModeById: saved.chartModeById ?? {},
    ifaceFilterById: saved.ifaceFilterById ?? {},
    tileMetricById: saved.tileMetricById ?? {},
    edgeStyle: saved.edgeStyle ?? 'curved',
    edgeAttach: saved.edgeAttach ?? 'auto',
    ospfCostSize: saved.ospfCostSize ?? 'md',
    ospfCostColor: saved.ospfCostColor ?? '#a5b4fc', // indigo-300
    layoutKind: saved.layoutKind ?? 'smart',
    mapGrid: saved.mapGrid ?? false,
    mapGridSize: clampGridSize(saved.mapGridSize ?? MAP_GRID_DEFAULT_SIZE),
    mapGridColorDark: saved.mapGridColorDark ?? MAP_GRID_DEFAULT_COLOR.dark,
    mapGridColorLight: saved.mapGridColorLight ?? MAP_GRID_DEFAULT_COLOR.light,
    wallboard: saved.wallboard ?? false,
    mapToolsCollapsed: saved.mapToolsCollapsed ?? false,
    mapToolsOrphansOnly: saved.mapToolsOrphansOnly ?? false,
    dashboardAll: saved.dashboardAll ?? true,
    dashboardIds: saved.dashboardIds ?? [],
    dashboardCycleS: saved.dashboardCycleS ?? 10,
};
const listeners = new Set<() => void>();

function persist(): void {
    if (typeof window === 'undefined') return;
    try {
        const {
            selectedDeviceId, activeMapId, chartModeById, ifaceFilterById, tileMetricById, edgeStyle, edgeAttach, ospfCostSize, ospfCostColor, layoutKind, wallboard, mapToolsCollapsed, mapToolsOrphansOnly,
            mapGrid, mapGridSize, mapGridColorDark, mapGridColorLight, dashboardAll, dashboardIds, dashboardCycleS,
        } = state;
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                selectedDeviceId, activeMapId, chartModeById, ifaceFilterById, tileMetricById, edgeStyle, edgeAttach, ospfCostSize, ospfCostColor, layoutKind, wallboard, mapToolsCollapsed, mapToolsOrphansOnly,
                mapGrid, mapGridSize, mapGridColorDark, mapGridColorLight, dashboardAll, dashboardIds, dashboardCycleS,
            }),
        );
    } catch {
        /* storage disabled or over quota - ignore */
    }
}

function emit() {
    persist();
    for (const l of listeners) l();
}
function subscribe(cb: () => void) {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
}

// Push history only when navigating (setView); popstate syncs without re-pushing, so
// there\'s no push/pop loop. Same no-op-if-unchanged guard as the other setters.
function applyView(view: View, opts: { push: boolean }): void {
    if (state.view === view) return;
    // Navigating closes any open mobile overlays.
    state = { ...state, view, navOpen: false, inspectorOpen: false };
    if (opts.push && typeof window !== 'undefined') {
        window.history.pushState({ view }, '', VIEW_TO_PATH[view]);
    }
    emit();
}

export function setView(view: View): void {
    applyView(view, { push: true });
}

/** Fired after navigateTo() changes the URL, so pages that read their own path/query re-render. */
export const NAVIGATE_EVENT = 'mymate:navigate';

/**
 * Go to a full URL (path + query) inside the app, eg the device page. The view follows the
 * path; `replace` swaps the history entry instead of adding one (range/tab tweaks).
 */
export function navigateTo(url: string, opts: { replace?: boolean } = {}): void {
    if (typeof window === 'undefined') return;
    if (opts.replace) window.history.replaceState({}, '', url);
    else window.history.pushState({}, '', url);
    applyView(pathToView(window.location.pathname), { push: false });
    window.dispatchEvent(new Event(NAVIGATE_EVENT));
}

export function selectDevice(id: number | null): void {
    if (state.selectedDeviceId !== id) {
        state = { ...state, selectedDeviceId: id };
        emit();
    }
}

export function useView(): View {
    return useSyncExternalStore(subscribe, () => state.view);
}

export function useSelectedDeviceId(): number | null {
    return useSyncExternalStore(subscribe, () => state.selectedDeviceId);
}

// Transient off-canvas UI: the mobile nav drawer + inspector sheet.
// Below `lg` the nav rail and inspector are overlays toggled by these flags; at `lg`+
// they\'re always-visible columns and the flags are ignored. Not persisted.
export function setNavOpen(open: boolean): void {
    if (state.navOpen === open) return;
    state = { ...state, navOpen: open };
    emit();
}

export function useNavOpen(): boolean {
    return useSyncExternalStore(subscribe, () => state.navOpen);
}

export function setInspectorOpen(open: boolean): void {
    if (state.inspectorOpen === open) return;
    state = { ...state, inspectorOpen: open };
    emit();
}

export function useInspectorOpen(): boolean {
    return useSyncExternalStore(subscribe, () => state.inspectorOpen);
}

export function setActiveMap(id: number | null): void {
    if (state.activeMapId !== id) {
        state = { ...state, activeMapId: id };
        emit();
    }
}

export function useActiveMapId(): number | null {
    return useSyncExternalStore(subscribe, () => state.activeMapId);
}

// Per-device inspector prefs - each device remembers its own chart
// mode + interface filter; no bleed across devices. Persisted to localStorage.
// getSnapshot returns a primitive (string), so it stays referentially stable.
export function setDeviceChartMode(id: number, mode: ChartMode): void {
    if ((state.chartModeById[id] ?? 'rate') === mode) return;
    state = { ...state, chartModeById: { ...state.chartModeById, [id]: mode } };
    emit();
}

// Default to throughput (rate) - meaningful even without a known speed, unlike util%.
export function useDeviceChartMode(id: number): ChartMode {
    return useSyncExternalStore(subscribe, () => state.chartModeById[id] ?? 'rate');
}

export function setDeviceIfaceFilter(id: number, filter: IfaceFilter): void {
    if ((state.ifaceFilterById[id] ?? 'all') === filter) return;
    state = { ...state, ifaceFilterById: { ...state.ifaceFilterById, [id]: filter } };
    emit();
}

export function useDeviceIfaceFilter(id: number): IfaceFilter {
    return useSyncExternalStore(subscribe, () => state.ifaceFilterById[id] ?? 'all');
}

// Per-device map-tile resource: throughput (default) / cpu / mem / temp. Persisted so a
// tile keeps showing the metric the operator picked across reloads.
export function setDeviceTileMetric(id: number, metric: TileMetric): void {
    if ((state.tileMetricById[id] ?? 'throughput') === metric) return;
    state = { ...state, tileMetricById: { ...state.tileMetricById, [id]: metric } };
    emit();
}

export function useDeviceTileMetric(id: number): TileMetric {
    return useSyncExternalStore(subscribe, () => state.tileMetricById[id] ?? 'throughput');
}

// Map edge geometry: curved (bezier, default) or straight. Global +
// persisted; UtilEdge reads it to pick the path helper. Geometry only - no colour logic.
export function setEdgeStyle(style: EdgeStyle): void {
    if (state.edgeStyle === style) return;
    state = { ...state, edgeStyle: style };
    emit();
}

export function useEdgeStyle(): EdgeStyle {
    return useSyncExternalStore(subscribe, () => state.edgeStyle);
}

// How links attach to cards: 'auto' floats each end to the facing side (re-picks as cards move),
// 'fixed' keeps the side an operator pinned by dragging. Global + persisted; UtilEdge reads it.
export function setEdgeAttach(attach: EdgeAttach): void {
    if (state.edgeAttach === attach) return;
    state = { ...state, edgeAttach: attach };
    emit();
}

export function useEdgeAttach(): EdgeAttach {
    return useSyncExternalStore(subscribe, () => state.edgeAttach);
}

// OSPF cost badge size + colour on map links. Global + persisted; UtilEdge reads them so an
// operator can make the cost legible against their own planning view (GitHub #22).
export function setOspfCostSize(size: OspfCostSize): void {
    if (state.ospfCostSize === size) return;
    state = { ...state, ospfCostSize: size };
    emit();
}

export function useOspfCostSize(): OspfCostSize {
    return useSyncExternalStore(subscribe, () => state.ospfCostSize);
}

export function setOspfCostColor(color: string): void {
    if (state.ospfCostColor === color) return;
    state = { ...state, ospfCostColor: color };
    emit();
}

export function useOspfCostColor(): string {
    return useSyncExternalStore(subscribe, () => state.ospfCostColor);
}

// Auto-layout algorithm: which arrange the map\'s "Tidy" menu last
// applied. Global + persisted; MapCanvas reads it to default the menu selection.
export function setLayoutKind(kind: LayoutKind): void {
    if (state.layoutKind === kind) return;
    state = { ...state, layoutKind: kind };
    emit();
}

export function useLayoutKind(): LayoutKind {
    return useSyncExternalStore(subscribe, () => state.layoutKind);
}

// Map dot grid: on/off from the map toolbar; size + per-theme colour from Settings > Theme.
// Global + persisted per browser, like the link toggles beside it. The size doubles as the
// snap grid, so the dots always mark where a dragged card can land.
export function setMapGrid(on: boolean): void {
    if (state.mapGrid === on) return;
    state = { ...state, mapGrid: on };
    emit();
}

export function useMapGrid(): boolean {
    return useSyncExternalStore(subscribe, () => state.mapGrid);
}

export function setMapGridSize(size: number): void {
    const v = clampGridSize(size);
    if (state.mapGridSize === v) return;
    state = { ...state, mapGridSize: v };
    emit();
}

export function useMapGridSize(): number {
    return useSyncExternalStore(subscribe, () => state.mapGridSize);
}

export function setMapGridColor(theme: 'dark' | 'light', color: string): void {
    const key = theme === 'light' ? 'mapGridColorLight' : 'mapGridColorDark';
    if (state[key] === color) return;
    state = { ...state, [key]: color };
    emit();
}

export function useMapGridColor(theme: 'dark' | 'light'): string {
    return useSyncExternalStore(subscribe, () => (theme === 'light' ? state.mapGridColorLight : state.mapGridColorDark));
}

// Wallboard / TV mode: chrome-free fullscreen presentation. Global +
// persisted so a TV reload stays in wallboard. The Fullscreen API call lives in the
// toggling component (it needs a user gesture); AppShell syncs this flag with
// `fullscreenchange` so an Esc-exit can\'t leave the UI stuck.
export function setWallboard(on: boolean): void {
    if (state.wallboard === on) return;
    state = { ...state, wallboard: on };
    emit();
}

export function useWallboard(): boolean {
    return useSyncExternalStore(subscribe, () => state.wallboard);
}

// Map tools panel collapsed to a slim rail on the right of the map, freeing the canvas.
// Global + persisted. Desktop (lg+) only; below lg the inspector is a slide-over sheet anyway.
export function setMapToolsCollapsed(collapsed: boolean): void {
    if (state.mapToolsCollapsed === collapsed) return;
    state = { ...state, mapToolsCollapsed: collapsed };
    emit();
}

export function useMapToolsCollapsed(): boolean {
    return useSyncExternalStore(subscribe, () => state.mapToolsCollapsed);
}

// Map tools device list filter: only orphaned devices (on no map at all) instead of everything
// not on the current map. Global + persisted.
export function setMapToolsOrphansOnly(on: boolean): void {
    if (state.mapToolsOrphansOnly === on) return;
    state = { ...state, mapToolsOrphansOnly: on };
    emit();
}

export function useMapToolsOrphansOnly(): boolean {
    return useSyncExternalStore(subscribe, () => state.mapToolsOrphansOnly);
}

// Dashboard selection + cadence. Persisted per browser. `dashboardAll`
// is a mode flag so newly-added devices appear automatically; `dashboardIds` is the
// explicit pick used when it\'s off (reconciled against the live list in the view).
export function setDashboardAll(on: boolean): void {
    if (state.dashboardAll === on) return;
    state = { ...state, dashboardAll: on };
    emit();
}

export function useDashboardAll(): boolean {
    return useSyncExternalStore(subscribe, () => state.dashboardAll);
}

export function toggleDashboardId(id: number): void {
    const has = state.dashboardIds.includes(id);
    const dashboardIds = has ? state.dashboardIds.filter((d) => d !== id) : [...state.dashboardIds, id];
    state = { ...state, dashboardIds };
    emit();
}

export function useDashboardIds(): number[] {
    return useSyncExternalStore(subscribe, () => state.dashboardIds);
}

export function setDashboardCycleS(seconds: number): void {
    const v = Math.max(2, Math.round(seconds));
    if (state.dashboardCycleS === v) return;
    state = { ...state, dashboardCycleS: v };
    emit();
}

export function useDashboardCycleS(): number {
    return useSyncExternalStore(subscribe, () => state.dashboardCycleS);
}

// Keep the store in sync with browser Back/Forward, and normalise the initial URL.
// Registered at module scope (runs once on import) - not a React effect, so StrictMode
// can\'t double-register and there\'s no listener to clean up.
if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => applyView(pathToView(window.location.pathname), { push: false }));
    // the device page's own path + query (ids, tab, graph range) is the page, keep it as is
    if (state.view !== 'device') window.history.replaceState({ view: state.view }, '', VIEW_TO_PATH[state.view]);
}
