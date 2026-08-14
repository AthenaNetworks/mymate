import { useState } from 'react';
import { useToolRunner, type TraceResult, type TraceHop } from '../api/tools';
import { ToolCard, RunButton, StatusPill, ErrorStrip, inputCls, th, td, fmtMs } from './shared';

const ROUNDS = [15, 30, 60];

function lossTone(hop: TraceHop): string {
    if (hop.sent === 0) return 'text-white/25';
    if (hop.loss_pct <= 0) return 'text-emerald-300';
    if (hop.loss_pct < 20) return 'text-amber-300';
    return 'text-rose-300';
}

/** MTR-style path trace to an operator-typed target. Same hop accounting and cell layout as
 *  the device inspector's Trace, just aimed anywhere. Rows fill in as mtr streams. */
export function TraceTool() {
    const [target, setTarget] = useState('');
    const [rounds, setRounds] = useState(30);
    const { run, running, begin, stop, startFailed, expired } = useToolRunner<TraceResult>('trace');

    const r = run?.result;
    const hops = r?.hops ?? [];
    const maxAvg = hops.reduce((m, h) => Math.max(m, h.avg_ms ?? 0), 0) || 1;

    function onStart(): void {
        const t = target.trim();
        if (t !== '') begin({ target: t, rounds });
    }

    return (
        <ToolCard title="Traceroute (MTR)" description="Live hop-by-hop path and per-hop loss/latency from this server to a target.">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[16rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Target</label>
                    <input
                        className={inputCls}
                        placeholder="host or IP, e.g. 8.8.8.8"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && !running && onStart()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Rounds</label>
                    <div className="flex items-center gap-0.5 rounded-lg bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                        {ROUNDS.map((c) => (
                            <button
                                key={c}
                                onClick={() => setRounds(c)}
                                disabled={running}
                                className={`rounded-md px-2.5 py-1.5 tabular-nums transition-colors duration-200 disabled:opacity-40 ${
                                    rounds === c ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70'
                                }`}
                            >
                                {c}
                            </button>
                        ))}
                    </div>
                </div>
                <RunButton running={running} onStart={onStart} onStop={stop} startLabel="Trace" disabled={target.trim() === ''} />
            </div>

            {startFailed && <div className="mt-3"><ErrorStrip>The server refused to start the trace.</ErrorStrip></div>}
            {run?.status === 'failed' && <div className="mt-3"><ErrorStrip>{run.error ?? 'The trace failed.'}</ErrorStrip></div>}
            {expired && !startFailed && (
                <p className="mt-3 text-xs text-white/40">This run expired. Trace again for a fresh path.</p>
            )}

            {r && (
                <div className="mt-4 flex items-center gap-3">
                    <StatusPill status={run.status} running={running} />
                    <span className="font-mono text-xs text-white/45">{run.target}</span>
                    <span className="font-mono text-xs tabular-nums text-white/35">
                        round {r.rounds_done}/{r.rounds_total}
                    </span>
                </div>
            )}

            {hops.length > 0 && (
                <div className="mt-3 max-h-[52vh] overflow-auto rounded-xl ring-1 ring-white/[0.06]">
                    <table className="w-full min-w-[42rem] text-left text-sm">
                        <thead className="sticky top-0 z-10 bg-surface-2">
                            <tr>
                                <th className={`${th} w-10 text-right`}>#</th>
                                <th className={th}>Host</th>
                                <th className={`${th} text-right`}>Loss%</th>
                                <th className={`${th} text-right`}>Last</th>
                                <th className={`${th} text-right`}>Avg</th>
                                <th className={`${th} text-right`}>Best</th>
                                <th className={`${th} text-right`}>Wrst</th>
                                <th className={`${th} text-right`}>StDev</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.04]">
                            {hops.map((hop) => {
                                const isTarget = hop.ip !== null && hop.ip === run?.target;
                                const ratio = (hop.avg_ms ?? 0) / maxAvg;
                                return (
                                    <tr key={hop.ttl} className={isTarget ? 'bg-emerald-400/[0.04]' : ''}>
                                        <td className={`${td} text-right text-white/30`}>{hop.ttl}</td>
                                        <td className="px-3 py-1.5">
                                            {hop.ip === null ? (
                                                <span className="animate-pulse font-mono text-white/25">* * *</span>
                                            ) : (
                                                <span className="flex min-w-0 max-w-[20rem] items-center gap-2">
                                                    <span className="min-w-0">
                                                        {hop.ptr ? (
                                                            <>
                                                                <span className="block truncate text-white/85">{hop.ptr}</span>
                                                                <span className="block truncate font-mono text-[11px] text-white/35">{hop.ip}</span>
                                                            </>
                                                        ) : (
                                                            <span className="block truncate font-mono text-white/80">{hop.ip}</span>
                                                        )}
                                                    </span>
                                                    {isTarget && (
                                                        <span className="shrink-0 rounded-full bg-emerald-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300 ring-1 ring-emerald-400/20">
                                                            target
                                                        </span>
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className={`${td} text-right ${lossTone(hop)}`}>
                                            {hop.loss_pct.toFixed(hop.loss_pct % 1 === 0 ? 0 : 1)}
                                        </td>
                                        <td className={`${td} text-right text-white/70`}>{fmtMs(hop.last_ms)}</td>
                                        <td className={`relative ${td} text-right text-white/90`}>
                                            <span
                                                aria-hidden
                                                className="absolute inset-y-[3px] left-0 rounded-sm bg-emerald-400/15"
                                                style={{ width: `${Math.max(hop.avg_ms === null ? 0 : 4, ratio * 100)}%` }}
                                            />
                                            <span className="relative">{fmtMs(hop.avg_ms)}</span>
                                        </td>
                                        <td className={`${td} text-right text-white/45`}>{fmtMs(hop.best_ms)}</td>
                                        <td className={`${td} text-right text-white/45`}>{fmtMs(hop.worst_ms)}</td>
                                        <td className={`${td} text-right text-white/45`}>{fmtMs(hop.stdev_ms)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {r && hops.length === 0 && running && (
                <p className="mt-3 text-xs text-white/40">Waiting for the first hop...</p>
            )}
        </ToolCard>
    );
}
