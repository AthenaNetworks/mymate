import { useEffect } from 'react';
import { ReactFlowProvider } from '@xyflow/react';
import { ArrowsIn } from '@phosphor-icons/react';
import { setWallboard, useView, useWallboard } from '../../lib/shellStore';
import { TopBar } from './TopBar';
import { NavRail } from './NavRail';
import { UpdateNotice } from './UpdateNotice';
import { MapCanvas } from '../topology/components/MapCanvas';
import { DeviceInspector } from '../topology/components/DeviceInspector';
import { GeoView } from '../geo/components/GeoView';
import { DashboardView } from '../dashboard/components/DashboardView';
import { DevicesView } from '../devices/components/DevicesView';
import { DiscoveryView } from '../discovery/components/DiscoveryView';
import { OutagesView } from '../outages/components/OutagesView';
import { useOutages } from '../outages/api/getOutages';
import { SettingsView } from '../settings/components/SettingsView';
import { AlertsView } from '../alerts/components/AlertsView';
import { UpgradesView } from '../upgrades/components/UpgradesView';
import { BackupsView } from '../backups/components/BackupsView';
import { ImportView } from '../import/components/ImportView';

/**
 * The NOC console shell: a full-width top bar over a row of [nav rail | active
 * view | inspector]. The inspector slot is wired in . Mesh-glow backdrop
 * is fixed + GPU-cheap; blur lives only on the fixed chrome (bar/rail), never on
 * the React Flow canvas.
 *
 * Wallboard mode: strips the chrome (top bar, nav rail,
 * inspector) for a fullscreen big-screen presentation; an Esc-exit of fullscreen
 * syncs the flag back off so the UI can\'t get stuck.
 */
export function AppShell() {
    const view = useView();
    const wallboard = useWallboard();
    const openOutages = useOutages('open');

    // Keep wallboard in step with the Fullscreen API: leaving fullscreen (Esc, or the
    // browser\'s own control) exits wallboard. Esc also works when fullscreen is
    // unsupported/declined. Registered once - the handlers are no-ops when already off.
    useEffect(() => {
        const onFullscreen = () => {
            if (!document.fullscreenElement) setWallboard(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setWallboard(false);
        };
        document.addEventListener('fullscreenchange', onFullscreen);
        window.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('fullscreenchange', onFullscreen);
            window.removeEventListener('keydown', onKey);
        };
    }, []);

    const exitWallboard = () => {
        setWallboard(false);
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
    };

    const mainView = (
        <main className="relative min-w-0 flex-1">
            {view === 'map' && (
                <ReactFlowProvider>
                    <MapCanvas />
                </ReactFlowProvider>
            )}
            {view === 'geo' && <GeoView />}
            {view === 'dashboard' && <DashboardView />}
            {view === 'devices' && <DevicesView />}
            {view === 'discovery' && <DiscoveryView />}
            {view === 'outages' && <OutagesView />}
            {view === 'alerts' && <AlertsView />}
            {view === 'upgrades' && <UpgradesView />}
            {view === 'backups' && <BackupsView />}
            {view === 'settings' && <SettingsView />}
            {view === 'import' && <ImportView />}
        </main>
    );

    const meshGlow = (
        <div
            aria-hidden
            className="pointer-events-none fixed inset-0 -z-10"
            style={{
                background:
                    'radial-gradient(55rem 55rem at 10% -15%, rgba(16,185,129,0.12), transparent 60%),' +
                    'radial-gradient(45rem 45rem at 105% 115%, rgba(99,102,241,0.10), transparent 55%)',
            }}
        />
    );

    // Wallboard: no top bar / nav rail / inspector - just the active view + an exit affordance.
    if (wallboard) {
        return (
            <div className="relative flex h-screen flex-col overflow-hidden bg-[#060608] text-zinc-100">
                {meshGlow}
                <div className="flex min-h-0 flex-1">{mainView}</div>
                <button
                    onClick={exitWallboard}
                    title="Exit wallboard (Esc)"
                    className="fixed right-4 top-4 z-30 flex items-center gap-2 rounded-full bg-white/[0.06] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 backdrop-blur-xl transition-all duration-300 ease-fluid hover:bg-white/10 hover:text-white active:scale-[0.98]"
                >
                    <ArrowsIn weight="bold" className="h-4 w-4" />
                    Exit wallboard
                </button>
            </div>
        );
    }

    return (
        // Height leaves room for the demo marketing bar when present (var set by the demo
        // wrapper in app.tsx); defaults to the full viewport on a real instance. Safe-area
        // insets keep the chrome clear of a notch / home indicator (0 in a normal tab, only
        // non-zero when installed to the home screen or in notched landscape).
        <div className="relative flex h-[calc(100dvh-var(--app-inset-top,0px))] flex-col overflow-hidden bg-[#060608] pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] text-zinc-100">
            {meshGlow}

            <TopBar />

            <div className="flex min-h-0 flex-1">
                <NavRail outageCount={openOutages.data?.length ?? 0} />
                {mainView}
                {view === 'map' && <DeviceInspector />}
            </div>

            {/* One-time "update available" notice after login (per-version "don't show again"). */}
            <UpdateNotice />
        </div>
    );
}
