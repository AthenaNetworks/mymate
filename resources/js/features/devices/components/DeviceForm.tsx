import { useState, type FormEvent } from 'react';
import { Plus } from '@phosphor-icons/react';
import { useCreateDevice } from '../api/createDevice';
import { useAgents } from '../../settings/api/agents';
import { useIsAdmin } from '../../auth/api/auth';
import type { DeviceType, PollMethod } from '../../../types';

const DEVICE_TYPES: { value: DeviceType; label: string }[] = [
    { value: 'unknown', label: 'Auto-detect type' },
    { value: 'router', label: 'Router' },
    { value: 'switch', label: 'Switch' },
    { value: 'ap', label: 'Access point' },
    { value: 'server', label: 'Server' },
    { value: 'internet', label: 'Internet / upstream' },
];

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid placeholder:text-white/30 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

export function DeviceForm() {
    const isAdmin = useIsAdmin();
    const create = useCreateDevice();
    const { data: agents } = useAgents();
    const [name, setName] = useState('');
    const [mgmtIp, setMgmtIp] = useState('');
    const [pollMethod, setPollMethod] = useState<PollMethod>('snmp');
    const [deviceType, setDeviceType] = useState<DeviceType>('unknown');
    const [agentId, setAgentId] = useState<string>(''); // '' = polled centrally

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!name.trim() || !mgmtIp.trim()) return;
        create.mutate(
            {
                name: name.trim(), mgmt_ip: mgmtIp.trim(), poll_method: pollMethod, device_type: deviceType,
                agent_id: agentId === '' ? null : Number(agentId),
            },
            { onSuccess: () => { setName(''); setMgmtIp(''); setDeviceType('unknown'); setAgentId(''); } },
        );
    }

    // Read-only viewers can\'t add devices (the backend 403s it).
    if (!isAdmin) return null;

    return (
        <form onSubmit={submit} className="space-y-2.5">
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" className={field} />
            <input value={mgmtIp} onChange={(e) => setMgmtIp(e.target.value)} placeholder="Management IP" className={field} />
            <select value={pollMethod} onChange={(e) => setPollMethod(e.target.value as PollMethod)} className={field}>
                <option value="snmp">SNMP</option>
                <option value="routeros">RouterOS API</option>
                <option value="none">Ping only (no throughput)</option>
            </select>
            <select value={deviceType} onChange={(e) => setDeviceType(e.target.value as DeviceType)} className={field}>
                {DEVICE_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                        {t.label}
                    </option>
                ))}
            </select>
            {/* Poll from the central app, or delegate to a remote agent on that network. */}
            {agents && agents.length > 0 && (
                <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={field}>
                    <option value="">Polled centrally (this server)</option>
                    {agents.map((a) => (
                        <option key={a.id} value={a.id}>
                            Via agent: {a.name}
                        </option>
                    ))}
                </select>
            )}

            {/* Island CTA with nested button-in-button trailing icon + magnetic hover. */}
            <button
                type="submit"
                disabled={create.isPending}
                className="group flex w-full items-center justify-between rounded-full bg-emerald-500 py-1.5 pl-5 pr-1.5 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-50"
            >
                <span>{create.isPending ? 'Adding...' : 'Add device'}</span>
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-950/15 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5 group-hover:-translate-y-px">
                    <Plus weight="bold" className="h-4 w-4" />
                </span>
            </button>
            {create.isError && <p className="text-xs text-rose-400/90">Couldn\'t add - check the fields (a valid IP is required).</p>}
        </form>
    );
}
