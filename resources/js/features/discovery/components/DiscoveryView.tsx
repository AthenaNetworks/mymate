import { useState } from 'react';
import { Broadcast, MagnifyingGlass } from '@phosphor-icons/react';
import { SubnetManager } from './SubnetManager';
import { CandidateReview } from './CandidateReview';
import { useSubnets } from '../api/getSubnets';

/**
 * Auto-discovery workspace - scan ranges on the left, the review queue on the right. A live
 * scan-progress banner shows whenever a sweep is running - user-triggered OR scheduled - from
 * the subnet's `scanning` flag (plus this browser's just-clicked map, which covers the brief
 * gap before the queued job starts).
 */
export function DiscoveryView() {
    const [scanning, setScanning] = useState<Record<number, string | null>>({});
    // Poll the subnets while the page is open so a background/scheduled sweep surfaces too;
    // the shared query cache also feeds SubnetManager.
    const { data: subnets } = useSubnets({ refetchInterval: 3500 });

    const active = (subnets ?? []).filter((s) => s.scanning || s.id in scanning);
    const anyScanning = active.length > 0;

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise">
                <header className="mb-6 flex items-center gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <MagnifyingGlass weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-base font-bold tracking-tight text-white">Auto-discovery</h1>
                        <p className="text-xs text-white/40">Scan subnets - review found devices</p>
                    </div>
                </header>

                {anyScanning && (
                    <div className="animate-rise mb-6 rounded-2xl bg-emerald-500/[0.07] p-4 ring-1 ring-emerald-400/20">
                        <div className="mb-2.5 flex items-center gap-2.5">
                            <Broadcast weight="bold" className="h-4 w-4 animate-pulse text-emerald-300" />
                            <span className="text-sm font-semibold text-emerald-100">
                                Scanning {active.length} subnet{active.length === 1 ? '' : 's'}
                            </span>
                            <span className="truncate font-mono text-[11px] text-emerald-300/70">
                                {active.map((s) => s.cidr).join('  ·  ')}
                            </span>
                        </div>
                        {/* Indeterminate bar - the sweep reports no %; the motion is the signal. */}
                        <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-emerald-500/15">
                            <span className="animate-scanbar bg-gradient-to-r from-emerald-400/0 via-emerald-400 to-emerald-400/0" />
                        </div>
                        <p className="mt-2 text-[11px] text-emerald-200/60">
                            Pinging each host, then trying SNMP, RouterOS and SSH - matches appear in the review queue as they're found.
                        </p>
                    </div>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[21rem_1fr]">
                    <div className="rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06] lg:sticky lg:top-0">
                        <SubnetManager scanning={scanning} setScanning={setScanning} />
                    </div>
                    <div className="rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
                        <CandidateReview open scanning={anyScanning} />
                    </div>
                </div>
            </div>
        </div>
    );
}
