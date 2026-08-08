import { useSyncExternalStore } from 'react';

/**
 * Tiny dependency-free shell store (same pattern as lib/toast.ts) for the NOC
 * console: which workspace view is active, and which device the inspector shows.
 * Lives in the shared core so the shell and the topology feature both read/write
 * it without a cross-feature import. No provider needed.
 */
export type View = 'map' | 'geo' | 'dashboard' | 'devices' | 'discovery' | 'graphs' | 'outages' | 'alerts' | 'upgrades' | 'backups' | 'settings' | 'import';

/** Per-device inspector view prefs. */
export type ChartMode = 'util' | 'rate';
export type IfaceFilter = 'all' | 'linked' | 'traffic';
/** Which resource a device's map tile shows. Re-exported from types for store use. */
export type TileMetric = 'throughput' | 'cpu' | 'mem' | 'temp';

/** Map link geometry - curved bezier (default) or straight point-to-point. */
export type EdgeStyle = 'curved' | 'straight';

/** OSPF cost badge size on the map - remembered per-browser (GitHub #22 readability). */
export type OspfCostSize = 'sm' | 'md' | 'lg';

/** Auto-layout algorithm - remembered per-browser; mirrors LayoutKind in lib/layout. */
export type LayoutKind = 'smart' | 'tree-tb' | 'tree-lr' | 'radial' | 'force';

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
    ospfCostSize: OspfCostSize; // OSPF cost badge size on links
    ospfCostColor: string; // OSPF cost badge colour (hex)
    layoutKind: LayoutKind; // last-chosen auto-layout algorithm
    wallboard: boolean; // big-screen / TV presentation mode
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
    discovery: '/discovery',
    graphs: '/graphs',
    outages: '/outages',
    alerts: '/alerts',
    upgrades: '/upgrades',
    backups: '/backups',
    settings: '/settings',
    import: '/import',
};

function pathToView(pathname: string): View {
    const p = pathname.replace(/\/+$/, '').toLowerCase() || '/';
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
    | 'ospfCostSize'
    | 'ospfCostColor'
    | 'layoutKind'
    | 'wallboard'
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
    ospfCostSize: saved.ospfCostSize ?? 'md',
    ospfCostColor: saved.ospfCostColor ?? '#a5b4fc', // indigo-300
    layoutKind: saved.layoutKind ?? 'smart',
    wallboard: saved.wallboard ?? false,
    dashboardAll: saved.dashboardAll ?? true,
    dashboardIds: saved.dashboardIds ?? [],
    dashboardCycleS: saved.dashboardCycleS ?? 10,
};
const listeners = new Set<() => void>();

function persist(): void {
    if (typeof window === 'undefined') return;
    try {
        const {
            selectedDeviceId, activeMapId, chartModeById, ifaceFilterById, tileMetricById, edgeStyle, ospfCostSize, ospfCostColor, layoutKind, wallboard,
            dashboardAll, dashboardIds, dashboardCycleS,
        } = state;
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                selectedDeviceId, activeMapId, chartModeById, ifaceFilterById, tileMetricById, edgeStyle, ospfCostSize, ospfCostColor, layoutKind, wallboard,
                dashboardAll, dashboardIds, dashboardCycleS,
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
    window.history.replaceState({ view: state.view }, '', VIEW_TO_PATH[state.view]);
}
