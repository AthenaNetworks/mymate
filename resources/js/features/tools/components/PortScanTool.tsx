import { useState } from 'react';
import { useToolRunner, type PortScanResult } from '../api/tools';
import { ToolCard, RunButton, StatusPill, ErrorStrip, inputCls, th, td } from './shared';

/** TCP connect scan of one target over the common ports, or a custom list. Open ports fill
 *  in live with a best-effort service label; a connect scan only proves something listens. */
export function PortScanTool() {
    const [target, setTarget] = useState('');
    const [ports, setPorts] = useState('');
    const { run, running, begin, stop, startFailed, expired } = useToolRunner<PortScanResult>('portscan');

    const r = run?.result;
    const pct = r && r.total > 0 ? Math.round((r.scanned / r.total) * 100) : 0;

    function onStart(): void {
        const t = target.trim();
        if (t === '') return;
        const body: Record<string, unknown> = { target: t };
        if (ports.trim() !== '') body.ports = ports.trim();
        begin(body);
    }

    return (
        <ToolCard title="Port map" description="Scan a target for open TCP ports. Blank ports = a common set (SSH, HTTP, RDP, Winbox, DB ports, ...).">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[14rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Target</label>
                    <input
                        className={inputCls}
                        placeholder="host or IP"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && !running && onStart()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <div className="min-w-[14rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Ports (optional)</label>
                    <input
                        className={inputCls}
                        placeholder="e.g. 22,80,443,8291"
                        value={ports}
                        onChange={(e) => setPorts(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && !running && onStart()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <RunButton running={running} onStart={onStart} onStop={stop} startLabel="Scan" disabled={target.trim() === ''} />
            </div>

            {startFailed && <div className="mt-3"><ErrorStrip>The server refused to start the scan.</ErrorStrip></div>}
            {run?.status === 'failed' && <div className="mt-3"><ErrorStrip>{run.error ?? 'The scan failed.'}</ErrorStrip></div>}
            {expired && !startFailed && (
                <p className="mt-3 text-xs text-white/40">This run expired. Scan again for fresh results.</p>
            )}

            {r && (
                <>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <StatusPill status={run.status} running={running} />
                        <span className="font-mono text-xs text-white/45">{run.target}</span>
                        <span className="font-mono text-xs tabular-nums text-white/35">
                            {r.scanned}/{r.total} ports - {r.open.length} open
                        </span>
                    </div>

                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/[0.06]">
                        <div className="h-full rounded-full bg-emerald-400/50 transition-all duration-300" style={{ width: `${pct}%` }} />
                    </div>

                    {r.open.length > 0 ? (
                        <div className="mt-3 overflow-hidden rounded-xl ring-1 ring-white/[0.06]">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-surface-2">
                                    <tr>
                                        <th className={`${th} w-24`}>Port</th>
                                        <th className={th}>Service</th>
                                        <th className={`${th} w-24`}>State</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/[0.04]">
                                    {r.open.map((p) => (
                                        <tr key={p.port}>
                                            <td className={`${td} text-emerald-200/90`}>{p.port}</td>
                                            <td className="px-3 py-1.5 text-sm text-white/70">{p.service ?? '-'}</td>
                                            <td className="px-3 py-1.5">
                                                <span className="rounded-full bg-emerald-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300 ring-1 ring-emerald-400/20">
                                                    open
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-3 text-xs text-white/40">
                            {running ? 'Scanning...' : 'No open ports found in the scanned set.'}
                        </p>
                    )}
                </>
            )}
        </ToolCard>
    );
}
