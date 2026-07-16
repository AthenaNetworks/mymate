import { useState } from 'react';
import { Check, Prohibit, CircleNotch } from '@phosphor-icons/react';
import { useCandidates } from '../api/getCandidates';
import { useApproveCandidate } from '../api/approveCandidate';
import { useIgnoreCandidate } from '../api/ignoreCandidate';
import { useIsAdmin } from '../../auth/api/auth';
import { relativeTime } from '../../../lib/relativeTime';
import type { DiscoveryCandidate, DiscoveryStatus } from '../../../types';

const credTag: Record<string, string> = {
    snmp: 'bg-sky-500/15 text-sky-300 ring-sky-400/20',
    routeros: 'bg-violet-500/15 text-violet-300 ring-violet-400/20',
    ssh: 'bg-amber-500/15 text-amber-300 ring-amber-400/25',
};

/** One tag per credential that authenticated against the host (SNMP / RouterOS / SSH). */
function CredentialTags({ candidate }: { candidate: DiscoveryCandidate }) {
    const creds = candidate.matched_credentials ?? [];
    if (creds.length === 0) {
        return (
            <span className="rounded-md bg-white/5 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/40 ring-1 ring-white/10">
                unidentified
            </span>
        );
    }
    return (
        <>
            {creds.map((c) => (
                <span
                    key={c.id}
                    title={`${c.type.toUpperCase()} credential: ${c.name}`}
                    className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ${credTag[c.type] ?? 'bg-white/5 text-white/40 ring-white/10'}`}
                >
                    <span className="uppercase tracking-wide">{c.type}</span>
                    <span className="max-w-[7rem] truncate font-normal opacity-70">{c.name}</span>
                </span>
            ))}
        </>
    );
}

const statusBadge: Record<DiscoveryStatus, string> = {
    new: 'text-emerald-300',
    approved: 'text-white/40',
    ignored: 'text-white/30',
};

export function CandidateReview({ open, scanning }: { open: boolean; scanning?: boolean }) {
    const isAdmin = useIsAdmin();
    const [onlyNew, setOnlyNew] = useState(true);
    const status: DiscoveryStatus | undefined = onlyNew ? 'new' : undefined;
    // While a scan is running, poll faster so found devices surface near-instantly.
    const { data: candidates, isLoading } = useCandidates(status, { enabled: open, refetchMs: scanning ? 3000 : 10_000 });
    const approve = useApproveCandidate();
    const ignore = useIgnoreCandidate();

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between px-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">
                    Review queue{candidates && candidates.length > 0 ? ` - ${candidates.length}` : ''}
                </p>
                <div className="flex items-center gap-1 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                    {([['New', true], ['All', false]] as const).map(([label, val]) => (
                        <button
                            key={label}
                            onClick={() => setOnlyNew(val)}
                            className={
                                'rounded-full px-2.5 py-0.5 transition-colors duration-300 ease-fluid ' +
                                (onlyNew === val ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70')
                            }
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {scanning && (
                <div className="flex items-center gap-2 rounded-xl bg-emerald-500/10 px-3 py-2 text-xs text-emerald-200 ring-1 ring-emerald-400/20">
                    <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" />
                    Scanning subnets - new devices appear here as they're found.
                </div>
            )}

            <ul className="space-y-1">
                {isLoading &&
                    [0, 1, 2].map((i) => (
                        <li key={i} className="flex items-center gap-2 rounded-xl bg-white/[0.02] px-2.5 py-3 ring-1 ring-white/[0.06]">
                            <span className="h-3 w-28 animate-pulse rounded bg-white/10" />
                            <span className="h-3 w-12 animate-pulse rounded bg-white/10" />
                        </li>
                    ))}

                {!isLoading && (candidates?.length ?? 0) === 0 && (
                    <li className="rounded-xl px-3 py-6 text-center text-xs text-white/35">
                        {scanning
                            ? 'Scanning... results will appear here.'
                            : onlyNew
                              ? 'Nothing new - run a scan to surface devices here.'
                              : 'No candidates yet.'}
                    </li>
                )}

                {candidates?.map((c) => (
                    <li
                        key={c.id}
                        className="flex items-center justify-between gap-2 rounded-xl bg-white/[0.02] px-2.5 py-2 ring-1 ring-white/[0.06]"
                    >
                        <span className="min-w-0">
                            <span className="flex flex-wrap items-center gap-1.5">
                                <span className="truncate font-mono text-sm text-white/85">{c.ip}</span>
                                <CredentialTags candidate={c} />
                            </span>
                            <span className="block truncate text-xs text-white/40">
                                {c.sysname ?? 'unidentified'} - seen {relativeTime(c.last_seen)}
                            </span>
                        </span>

                        {c.status === 'new' && isAdmin ? (
                            <span className="flex shrink-0 items-center gap-1">
                                <button
                                    onClick={() => approve.mutate(c.id)}
                                    disabled={approve.isPending}
                                    title="Approve -> add as a device"
                                    className="flex items-center gap-1 rounded-lg bg-emerald-500/90 px-2 py-1 text-xs font-semibold text-emerald-950 transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-95 disabled:opacity-40"
                                >
                                    <Check weight="bold" className="h-3.5 w-3.5" />
                                    Add
                                </button>
                                <button
                                    onClick={() => ignore.mutate(c.id)}
                                    disabled={ignore.isPending}
                                    title="Ignore"
                                    className="rounded-lg p-1.5 text-white/30 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-rose-400 disabled:opacity-40"
                                >
                                    <Prohibit weight="light" className="h-4 w-4" />
                                </button>
                            </span>
                        ) : (
                            <span className={`shrink-0 text-[11px] capitalize ${statusBadge[c.status]}`}>{c.status}</span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
