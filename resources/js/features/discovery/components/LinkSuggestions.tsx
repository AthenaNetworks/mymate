import { useState } from 'react';
import { ArrowCounterClockwise, Check, CheckCircle, LinkSimple, Prohibit } from '@phosphor-icons/react';
import { useAcceptAllLinkSuggestions, useLinkSuggestionAction, useLinkSuggestions } from '../api/linkSuggestions';
import { useIsAdmin } from '../../auth/api/auth';
import { relativeTime } from '../../../lib/relativeTime';
import { pushToast } from '../../../lib/toast';
import type { LinkSuggestion, LinkSuggestionEnd } from '../../../types';

const protocolLabel: Record<LinkSuggestion['protocol'], string> = { lldp: 'LLDP', cdp: 'CDP', mndp: 'MNDP' };

function errorDetail(e: unknown): string | undefined {
    return (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
}

function End({ end }: { end: LinkSuggestionEnd }) {
    return (
        <span className="min-w-0 truncate">
            <span className="text-white/85">{end.device_name}</span>
            <span className={`ml-1.5 font-mono text-xs ${end.interface_name ? 'text-white/50' : 'text-white/25 italic'}`}>
                {end.interface_name ?? 'port unknown'}
            </span>
        </span>
    );
}

/**
 * Links neighbour discovery found (GitHub #85): LLDP and CDP over SNMP, and what a MikroTik heard
 * over MNDP. Nothing is drawn until someone accepts it; an ignored one stays ignored.
 */
export function LinkSuggestions() {
    const isAdmin = useIsAdmin();
    const [status, setStatus] = useState<LinkSuggestion['status']>('new');
    const { data: suggestions, isLoading } = useLinkSuggestions(status);
    const accept = useLinkSuggestionAction('accept');
    const ignore = useLinkSuggestionAction('ignore');
    const restore = useLinkSuggestionAction('restore');
    const acceptAll = useAcceptAllLinkSuggestions();
    const count = suggestions?.length ?? 0;

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2 px-1">
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">
                    Suggested links{count > 0 ? ` - ${count}` : ''}
                </p>
                <div className="flex items-center gap-2">
                    {isAdmin && status === 'new' && count > 1 && (
                        <button
                            onClick={() =>
                                acceptAll.mutate(undefined, {
                                    onSuccess: (r) => pushToast({ title: `${r.created} link${r.created === 1 ? '' : 's'} added`, tone: 'info' }),
                                    onError: (e) => pushToast({ title: "Couldn't add the links", detail: errorDetail(e), tone: 'down' }),
                                })
                            }
                            disabled={acceptAll.isPending}
                            className="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] text-emerald-300 ring-1 ring-emerald-400/25 transition-colors duration-300 ease-fluid hover:bg-emerald-500/10 disabled:opacity-40"
                        >
                            <CheckCircle weight="bold" className="h-3.5 w-3.5" /> Accept all
                        </button>
                    )}
                    <div className="flex items-center gap-1 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                        {([['New', 'new'], ['Ignored', 'ignored']] as const).map(([label, val]) => (
                            <button
                                key={val}
                                onClick={() => setStatus(val)}
                                className={
                                    'rounded-full px-2.5 py-0.5 transition-colors duration-300 ease-fluid ' +
                                    (status === val ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70')
                                }
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            <ul className="space-y-1">
                {isLoading && (
                    <li className="flex items-center gap-2 rounded-xl bg-white/[0.02] px-2.5 py-3 ring-1 ring-white/[0.06]">
                        <span className="h-3 w-40 animate-pulse rounded bg-white/10" />
                    </li>
                )}

                {!isLoading && count === 0 && (
                    <li className="rounded-xl px-3 py-6 text-center text-xs text-white/35">
                        {status === 'new'
                            ? 'Nothing to suggest yet. Links appear here when devices report their LLDP, CDP or MikroTik neighbours on discovery.'
                            : 'No ignored suggestions.'}
                    </li>
                )}

                {suggestions?.map((s) => (
                    <li key={s.id} className="flex items-center justify-between gap-2 rounded-xl bg-white/[0.02] px-2.5 py-2 ring-1 ring-white/[0.06]">
                        <span className="min-w-0">
                            <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                                <End end={s.a} />
                                <LinkSimple weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/25" />
                                <End end={s.b} />
                            </span>
                            <span className="block truncate text-xs text-white/40">
                                {protocolLabel[s.protocol]} - seen {relativeTime(s.last_seen_at)}
                            </span>
                        </span>

                        {isAdmin && s.status === 'new' && (
                            <span className="flex shrink-0 items-center gap-1">
                                <button
                                    onClick={() =>
                                        accept.mutate(s.id, {
                                            onError: (e) => pushToast({ title: "Couldn't add the link", detail: errorDetail(e), tone: 'down' }),
                                        })
                                    }
                                    disabled={accept.isPending}
                                    title="Accept -> draw this link"
                                    className="flex items-center gap-1 rounded-lg bg-emerald-500/90 px-2 py-1 text-xs font-semibold text-emerald-950 transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-95 disabled:opacity-40"
                                >
                                    <Check weight="bold" className="h-3.5 w-3.5" /> Add
                                </button>
                                <button
                                    onClick={() => ignore.mutate(s.id)}
                                    disabled={ignore.isPending}
                                    title="Ignore"
                                    className="rounded-lg p-1.5 text-white/30 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-rose-400 disabled:opacity-40"
                                >
                                    <Prohibit weight="light" className="h-4 w-4" />
                                </button>
                            </span>
                        )}
                        {isAdmin && s.status === 'ignored' && (
                            <button
                                onClick={() => restore.mutate(s.id)}
                                disabled={restore.isPending}
                                title="Restore -> back to New"
                                className="flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs text-white/60 ring-1 ring-white/10 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/90 disabled:opacity-40"
                            >
                                <ArrowCounterClockwise weight="bold" className="h-3.5 w-3.5" /> Restore
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
