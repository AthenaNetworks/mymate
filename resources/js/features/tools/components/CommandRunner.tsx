import { useMemo, useState } from 'react';
import { Play, Stop, TerminalWindow, Warning } from '@phosphor-icons/react';
import { useIsAdmin } from '../../auth/api/auth';
import { useDevices } from '../../devices/api/getDevices';
import { CommandTemplateManager } from './CommandTemplateManager';
import { useCommandTemplates } from '../api/commandTemplates';
import { useCommandRun, useStartCommandRun, useStopCommandRun } from '../api/commandRuns';

const field = 'rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm text-white outline-none placeholder:text-white/25 focus:border-emerald-400/50';

export function CommandRunner() {
    const isAdmin = useIsAdmin();
    const devices = useDevices();
    const templates = useCommandTemplates(isAdmin);
    const start = useStartCommandRun();
    const stop = useStopCommandRun();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [command, setCommand] = useState('');
    const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(null);
    const [timeout, setTimeoutSeconds] = useState(30);
    const [confirmed, setConfirmed] = useState(false);
    const [runId, setRunId] = useState<string | null>(null);
    const run = useCommandRun(runId);
    const rows = useMemo(() => Object.values(run.data?.result.devices ?? {}), [run.data]);

    const toggle = (id: number) => {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else if (next.size < 100) next.add(id);
            return next;
        });
    };

    const submit = () => {
        const template = templates.data?.find((item) => item.id === selectedTemplateId);
        start.mutate(
            {
                device_ids: [...selected],
                command: template ? template.command : command.trim(),
                timeout: template?.timeout ?? timeout,
                confirm: confirmed,
                ...(template ? { template_id: template.id, command_hash: template.command_hash, template_updated_at: template.updated_at } : {}),
            },
            { onSuccess: (data) => setRunId(data.run_id) },
        );
    };

    if (!isAdmin) {
        return (
            <div className="rounded-xl border border-amber-400/20 bg-amber-400/[0.06] p-5 text-sm text-amber-100/80">
                <div className="flex items-center gap-2 font-semibold text-amber-200"><Warning className="h-4 w-4" /> Administrator access required</div>
                <p className="mt-2 text-xs text-white/45">Remote command execution is disabled for read-only operators.</p>
            </div>
        );
    }

    const running = run.data?.status === 'running' || start.isPending;

    return (
        <div className="space-y-4">
            <CommandTemplateManager />
            <section className="rounded-xl border border-white/[0.08] bg-white/[0.025] p-5">
                <div className="mb-4 flex items-start gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-lg bg-rose-500/15 text-rose-300 ring-1 ring-rose-400/20"><TerminalWindow className="h-5 w-5" /></span>
                    <div>
                        <h2 className="font-semibold text-white">SSH command runner</h2>
                        <p className="text-xs text-white/40">Run one non-interactive command against selected devices. Start with read-only commands.</p>
                    </div>
                </div>

                <label className="mb-1 block text-xs font-medium text-white/55">Command template</label>
                <select value={selectedTemplateId ?? ''} onChange={(e) => {
                    const id = e.target.value ? Number(e.target.value) : null;
                    const template = templates.data?.find((item) => item.id === id);
                    setSelectedTemplateId(id);
                    if (template) {
                        setCommand(template.command);
                        setTimeoutSeconds(template.timeout);
                    }
                }} className={`${field} mb-3 w-full`} disabled={running}>
                    <option value="">Enter a command manually…</option>
                    {(templates.data ?? []).filter((template) => template.enabled).map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}
                </select>

                <label className="mb-1 block text-xs font-medium text-white/55">Command</label>
                <textarea value={command} onChange={(e) => setCommand(e.target.value)} maxLength={4096} rows={3} className={`${field} w-full font-mono`} placeholder="/system resource print" disabled={running || selectedTemplateId !== null} />

                <div className="mt-3 flex flex-wrap items-end gap-3">
                    <label className="text-xs text-white/50">Timeout (seconds)
                        <input type="number" min={1} max={60} value={timeout} onChange={(e) => setTimeoutSeconds(Math.max(1, Math.min(60, Number(e.target.value) || 1)))} className={`${field} mt-1 block w-24`} disabled={running} />
                    </label>
                    <label className="flex max-w-md items-center gap-2 text-xs text-amber-100/70"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} disabled={running} className="accent-amber-400" /> I understand this executes the command on live devices.</label>
                    <button onClick={submit} disabled={running || selected.size === 0 || command.trim() === '' || !confirmed} className="inline-flex items-center gap-2 rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-35"><Play weight="fill" className="h-4 w-4" /> Run on {selected.size} device{selected.size === 1 ? '' : 's'}</button>
                    {running && runId && <button onClick={() => stop.mutate(runId)} className="inline-flex items-center gap-2 rounded-lg border border-rose-400/30 px-3 py-2 text-sm text-rose-200 hover:bg-rose-400/10"><Stop weight="fill" className="h-4 w-4" /> Stop</button>}
                </div>
                {start.isError && <p className="mt-3 text-xs text-rose-300">Could not start command run.</p>}
            </section>

            <section className="rounded-xl border border-white/[0.08] bg-white/[0.025] p-5">
                <div className="mb-3 flex items-center justify-between"><h3 className="text-sm font-semibold text-white">Targets</h3><span className="text-xs text-white/35">{selected.size} selected</span></div>
                <div className="grid gap-2 sm:grid-cols-2">
                    {(devices.data ?? []).map((device) => (
                        <label key={device.id} className="flex cursor-pointer items-center gap-3 rounded-lg border border-white/[0.06] px-3 py-2 text-sm text-white/75 hover:bg-white/[0.04]">
                            <input type="checkbox" checked={selected.has(device.id)} onChange={() => toggle(device.id)} disabled={running} className="accent-emerald-400" />
                            <span>{device.name}</span><span className="ml-auto font-mono text-[11px] text-white/30">{device.mgmt_ip}</span>
                        </label>
                    ))}
                </div>
                {devices.isLoading && <p className="text-xs text-white/40">Loading devices…</p>}
            </section>

            {run.data && <section className="rounded-xl border border-white/[0.08] bg-white/[0.025] p-5">
                <div className="mb-3 flex items-center justify-between"><h3 className="text-sm font-semibold text-white">Results</h3><span className="text-xs text-white/40">{run.data.status === 'done' ? 'Complete' : 'Running'}</span></div>
                <div className="space-y-3">
                    {rows.map((row) => <div key={row.device_id} className="rounded-lg border border-white/[0.06] p-3"><div className="flex items-center justify-between text-sm"><span className="font-medium text-white">{row.name}</span><span className={row.status === 'success' ? 'text-emerald-300' : row.status === 'failed' ? 'text-rose-300' : 'text-amber-200'}>{row.status}</span></div>{row.error && <p className="mt-2 text-xs text-rose-300">{row.error}</p>}{row.output && <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-black/30 p-2 font-mono text-[11px] leading-5 text-white/65">{row.output}</pre>}</div>)}
                </div>
            </section>}
        </div>
    );
}
