import { useState } from 'react';
import { MagnifyingGlass } from '@phosphor-icons/react';
import { SubnetManager } from './SubnetManager';
import { CandidateReview } from './CandidateReview';

/**
 * Auto-discovery workspace - scan ranges on the left, the review queue on the
 * right. `scanning` (subnet id -> last_scanned_at at scan-start) is held here so
 * both panes share it: SubnetManager drives it, CandidateReview reflects it.
 */
export function DiscoveryView() {
    const [scanning, setScanning] = useState<Record<number, string | null>>({});
    const anyScanning = Object.keys(scanning).length > 0;

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
