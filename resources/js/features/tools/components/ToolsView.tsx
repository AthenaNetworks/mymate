import { useState } from 'react';
import { Broadcast, Path, MagnifyingGlass, GridFour, Calculator, Globe, Wrench, type Icon } from '@phosphor-icons/react';
import { PingTool } from './PingTool';
import { TraceTool } from './TraceTool';
import { SweepTool } from './SweepTool';
import { PortScanTool } from './PortScanTool';
import { SubnetCalculator } from './SubnetCalculator';
import { BgpLookup } from './BgpLookup';

type TabId = 'ping' | 'trace' | 'sweep' | 'portscan' | 'calc' | 'bgp';

const TABS: { id: TabId; label: string; icon: Icon }[] = [
    { id: 'ping', label: 'Ping', icon: Broadcast },
    { id: 'trace', label: 'Traceroute', icon: Path },
    { id: 'sweep', label: 'IP scan', icon: MagnifyingGlass },
    { id: 'portscan', label: 'Port map', icon: GridFour },
    { id: 'calc', label: 'Subnet calc', icon: Calculator },
    { id: 'bgp', label: 'BGP lookup', icon: Globe },
];

/**
 * The Tools page: a tab per network utility. Only the active tool is mounted, so switching
 * tabs (or leaving the page) unmounts the previous one - which is exactly what cancels any
 * live run server-side via useToolRunner's unmount cleanup. No stray sweep keeps going once
 * you walk away from it.
 */
export function ToolsView() {
    const [tab, setTab] = useState<TabId>('ping');

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="mx-auto max-w-5xl">
                <header className="mb-5 flex items-center gap-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <Wrench weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-lg font-bold tracking-tight text-white">Tools</h1>
                        <p className="text-xs text-white/40">Network diagnostics run from this server.</p>
                    </div>
                </header>

                <div className="mb-5 flex flex-wrap gap-1 rounded-xl bg-white/[0.02] p-1 ring-1 ring-white/[0.06]">
                    {TABS.map((t) => {
                        const active = tab === t.id;
                        const Icon = t.icon;
                        return (
                            <button
                                key={t.id}
                                onClick={() => setTab(t.id)}
                                className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200 ${
                                    active ? 'bg-white/[0.06] text-white ring-1 ring-white/10' : 'text-white/50 hover:text-white/80'
                                }`}
                            >
                                <Icon weight={active ? 'fill' : 'light'} className={`h-4 w-4 ${active ? 'text-emerald-300' : ''}`} />
                                {t.label}
                            </button>
                        );
                    })}
                </div>

                <div className="animate-rise">
                    {tab === 'ping' && <PingTool />}
                    {tab === 'trace' && <TraceTool />}
                    {tab === 'sweep' && <SweepTool />}
                    {tab === 'portscan' && <PortScanTool />}
                    {tab === 'calc' && <SubnetCalculator />}
                    {tab === 'bgp' && <BgpLookup />}
                </div>
            </div>
        </div>
    );
}
