import { useState } from 'react';
import { useToolRunner, type PingResult } from '../api/tools';
import { ToolCard, RunButton, StatusPill, Stat, ErrorStrip, inputCls, fmtMs } from './shared';

const COUNTS = [1, 3, 5, 10, 20];

/** Live ICMP ping to a single target: running loss / min / avg / max / jitter plus a
 *  scrolling strip of the most recent probes, streamed a reply at a time. */
export function PingTool() {
    const [target, setTarget] = useState('');
    const [count, setCount] = useState(3);
    const { run, running, begin, stop, startFailed, expired } = useToolRunner<PingResult>('ping');

    const r = run?.result;
    // Newest probes first, capped - the backend already trims the history it streams.
    const recent = r ? [...r.probes].slice(-60).reverse() : [];

    function onStart(): void {
        const t = target.trim();
        if (t !== '') begin({ target: t, count });
    }

    return (
        <ToolCard title="Ping" description="ICMP echo to one host with live loss and latency. Streams a reply per second.">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[16rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Target</label>
                    <input
                        className={inputCls}
                        placeholder="host or IP, e.g. 1.1.1.1"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && !running && onStart()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Count</label>
                    <div className="flex items-center gap-0.5 rounded-lg bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                        {COUNTS.map((c) => (
                            <button
                                key={c}
                                onClick={() => setCount(c)}
                                disabled={running}
                                className={`rounded-md px-2.5 py-1.5 tabular-nums transition-colors duration-200 disabled:opacity-40 ${
                                    count === c ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70'
                                }`}
                            >
                                {c}
                            </button>
                        ))}
                    </div>
                </div>
                <RunButton running={running} onStart={onStart} onStop={stop} startLabel="Ping" disabled={target.trim() === ''} />
            </div>

            {startFailed && <div className="mt-3"><ErrorStrip>The server refused to start the ping.</ErrorStrip></div>}
            {run?.status === 'failed' && <div className="mt-3"><ErrorStrip>{run.error ?? 'The ping failed.'}</ErrorStrip></div>}
            {expired && !startFailed && (
                <p className="mt-3 text-xs text-white/40">This run expired. Ping again for a fresh result.</p>
            )}

            {r && (
                <>
                    <div className="mt-4 flex items-center gap-3">
                        <StatusPill status={run.status} running={running} />
                        <span className="font-mono text-xs text-white/45">{run.target}</span>
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                        <Stat label="Sent" value={r.sent} />
                        <Stat label="Recv" value={r.recv} />
                        <Stat
                            label="Loss"
                            value={`${r.loss_pct}%`}
                            tone={r.loss_pct <= 0 ? 'text-emerald-300' : r.loss_pct < 20 ? 'text-amber-300' : 'text-rose-300'}
                        />
                        <Stat label="Avg" value={`${fmtMs(r.avg_ms)}`} />
                        <Stat label="Best" value={`${fmtMs(r.best_ms)}`} />
                        <Stat label="Worst" value={`${fmtMs(r.worst_ms)}`} />
                    </div>

                    {recent.length > 0 && (
                        <div className="mt-3 max-h-56 overflow-auto rounded-xl bg-black/20 p-3 font-mono text-xs leading-relaxed ring-1 ring-white/[0.06]">
                            {recent.map((p) => (
                                <div key={p.seq} className="flex items-center gap-3">
                                    <span className="w-14 shrink-0 text-white/30">seq {p.seq}</span>
                                    {p.ms === null ? (
                                        <span className="text-rose-300/80">request timed out</span>
                                    ) : (
                                        <span className="text-emerald-200/90">reply in {p.ms.toFixed(2)} ms</span>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </ToolCard>
    );
}
