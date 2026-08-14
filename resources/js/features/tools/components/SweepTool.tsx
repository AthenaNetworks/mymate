import { useState } from 'react';
import { CircleNotch } from '@phosphor-icons/react';
import { useToolRunner, type SweepResult } from '../api/tools';
import { ToolCard, RunButton, StatusPill, ErrorStrip, inputCls, th, td } from './shared';

/** Sweep an IPv4 subnet for live hosts, then enrich each with reverse DNS, a NetBIOS name/MAC
 *  and (optionally) open ports. Hosts appear as fping finds them, details fill in after. */
export function SweepTool() {
    const [cidr, setCidr] = useState('');
    const [scanPorts, setScanPorts] = useState(false);
    const [ports, setPorts] = useState('');
    const { run, running, begin, stop, startFailed, expired } = useToolRunner<SweepResult>('sweep');

    const r = run?.result;

    function onStart(): void {
        const c = cidr.trim();
        if (c === '') return;
        const body: Record<string, unknown> = { cidr: c, scan_ports: scanPorts };
        if (scanPorts && ports.trim() !== '') body.ports = ports.trim();
        begin(body);
    }

    return (
        <ToolCard title="IP scan" description="Sweep an IPv4 subnet for live hosts, with reverse DNS, NetBIOS name/MAC and optional port scan.">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[14rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Subnet (CIDR)</label>
                    <input
                        className={inputCls}
                        placeholder="e.g. 192.168.1.0/24"
                        value={cidr}
                        onChange={(e) => setCidr(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && !running && onStart()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <label className="flex cursor-pointer items-center gap-2 pb-2 text-xs text-white/60">
                    <input
                        type="checkbox"
                        checked={scanPorts}
                        onChange={(e) => setScanPorts(e.target.checked)}
                        disabled={running}
                        className="h-4 w-4 rounded border-white/20 bg-white/5 text-emerald-500 focus:ring-emerald-400/40"
                    />
                    Scan ports
                </label>
                {scanPorts && (
                    <div className="min-w-[12rem] flex-1">
                        <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Ports (optional)</label>
                        <input
                            className={inputCls}
                            placeholder="common set if blank"
                            value={ports}
                            onChange={(e) => setPorts(e.target.value)}
                            spellCheck={false}
                            autoComplete="off"
                        />
                    </div>
                )}
                <RunButton running={running} onStart={onStart} onStop={stop} startLabel="Scan" disabled={cidr.trim() === ''} />
            </div>

            {startFailed && <div className="mt-3"><ErrorStrip>The server refused to start the sweep. Check the subnet size.</ErrorStrip></div>}
            {run?.status === 'failed' && <div className="mt-3"><ErrorStrip>{run.error ?? 'The sweep failed.'}</ErrorStrip></div>}
            {expired && !startFailed && (
                <p className="mt-3 text-xs text-white/40">This run expired. Sweep again for fresh results.</p>
            )}

            {r && (
                <>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <StatusPill status={run.status} running={running} />
                        <span className="font-mono text-xs text-white/45">{run.target}</span>
                        <span className="font-mono text-xs tabular-nums text-white/35">
                            {r.alive} live of {r.total}
                            {running && <span className="ml-2 text-white/30">- {r.phase}</span>}
                        </span>
                    </div>

                    {r.hosts.length > 0 ? (
                        <div className="mt-3 max-h-[52vh] overflow-auto rounded-xl ring-1 ring-white/[0.06]">
                            <table className="w-full min-w-[44rem] text-left text-sm">
                                <thead className="sticky top-0 z-10 bg-surface-2">
                                    <tr>
                                        <th className={th}>Address</th>
                                        <th className={th}>Reverse DNS</th>
                                        <th className={th}>NetBIOS</th>
                                        <th className={th}>MAC</th>
                                        <th className={th}>Open ports</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/[0.04]">
                                    {r.hosts.map((h) => (
                                        <tr key={h.ip}>
                                            <td className={`${td} text-white/85`}>{h.ip}</td>
                                            <td className="px-3 py-1.5 text-sm text-white/60">
                                                {h.pending ? (
                                                    <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin text-white/25" />
                                                ) : (
                                                    h.rdns ?? <span className="text-white/25">-</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5 text-sm text-white/60">
                                                {h.netbios ? (
                                                    <span>
                                                        {h.netbios}
                                                        {h.group && <span className="ml-1 text-white/30">/ {h.group}</span>}
                                                    </span>
                                                ) : (
                                                    <span className="text-white/25">-</span>
                                                )}
                                            </td>
                                            <td className={`${td} text-xs text-white/45`}>{h.mac ?? <span className="text-white/25">-</span>}</td>
                                            <td className="px-3 py-1.5 font-mono text-xs text-white/60">
                                                {h.ports.length > 0 ? (
                                                    <span className="flex flex-wrap gap-1">
                                                        {h.ports.map((p) => (
                                                            <span
                                                                key={p.port}
                                                                title={p.service ?? undefined}
                                                                className="rounded bg-emerald-400/10 px-1.5 py-0.5 text-emerald-200/90 ring-1 ring-emerald-400/15"
                                                            >
                                                                {p.port}
                                                            </span>
                                                        ))}
                                                    </span>
                                                ) : (
                                                    <span className="text-white/25">-</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="mt-3 text-xs text-white/40">
                            {running ? 'Sweeping the range...' : 'No live hosts found.'}
                        </p>
                    )}
                </>
            )}
        </ToolCard>
    );
}
