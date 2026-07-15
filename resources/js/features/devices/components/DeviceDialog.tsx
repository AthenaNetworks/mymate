import { useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { X } from '@phosphor-icons/react';
import { useCreateDevice } from '../api/createDevice';
import { useUpdateDevice } from '../api/updateDevice';
import { useAgents } from '../../settings/api/agents';
import { useCredentials } from '../../settings/api/credentials';
import { useIsAdmin } from '../../auth/api/auth';
import { Toggle } from '../../../components/Toggle';
import type { Device, DeviceType, PollMethod } from '../../../types';

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
    'transition duration-300 ease-fluid placeholder:text-white/30 [color-scheme:dark] focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

export type DeviceDialogDefaults = { name?: string; mgmt_ip?: string; device_type?: DeviceType; poll_method?: PollMethod };

/**
 * Create or edit a device in a main-window modal. Used from the Devices view, the map toolbar
 * (add a device / a generic internet-uplink object straight onto the canvas), and the inspector
 * (edit the selected device's options). On create it hands the new device back via onCreated so
 * the caller can drop it on the current map.
 */
export function DeviceDialog({
    mode,
    device,
    defaults,
    onClose,
    onCreated,
}: {
    mode: 'create' | 'edit';
    device?: Device;
    defaults?: DeviceDialogDefaults;
    onClose: () => void;
    onCreated?: (device: Device) => void;
}) {
    const isAdmin = useIsAdmin();
    const create = useCreateDevice();
    const update = useUpdateDevice();
    const { data: agents } = useAgents();
    const { data: credentials } = useCredentials();

    const [name, setName] = useState(device?.name ?? defaults?.name ?? '');
    const [mgmtIp, setMgmtIp] = useState(device?.mgmt_ip ?? defaults?.mgmt_ip ?? '');
    const [pollMethod, setPollMethod] = useState<PollMethod>(device?.poll_method ?? defaults?.poll_method ?? 'snmp');
    const [deviceType, setDeviceType] = useState<DeviceType>(device?.device_type ?? defaults?.device_type ?? 'unknown');
    const [agentId, setAgentId] = useState<string>(device?.agent_id != null ? String(device.agent_id) : '');
    const [credentialId, setCredentialId] = useState<string>(device?.credential_id != null ? String(device.credential_id) : '');
    const [monitored, setMonitored] = useState<boolean>(device?.monitored ?? true);

    const busy = create.isPending || update.isPending;
    const isError = create.isError || update.isError;
    // Surface the server's actual reason (e.g. "The mgmt ip has already been taken.") rather than
    // a generic message, so a duplicate IP or bad field is obvious.
    const serverError = (() => {
        const e = (create.error ?? update.error) as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } } | null;
        const data = e?.response?.data;
        if (!data) return null;
        const firstField = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        return firstField ?? data.message ?? null;
    })();
    const needsCredential = pollMethod !== 'none';
    const matchingCreds = (credentials ?? []).filter((c) => c.type === pollMethod);

    // Switching poll method invalidates a credential of the old type - clear it so we never
    // submit e.g. a RouterOS credential against an SNMP device.
    function changePollMethod(m: PollMethod) {
        setPollMethod(m);
        setCredentialId('');
    }

    if (!isAdmin) return null;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!name.trim() || !mgmtIp.trim()) return;
        const credId = needsCredential && credentialId !== '' ? Number(credentialId) : null;
        const agent = agentId === '' ? null : Number(agentId);

        if (mode === 'create') {
            create.mutate(
                { name: name.trim(), mgmt_ip: mgmtIp.trim(), poll_method: pollMethod, device_type: deviceType, credential_id: credId, agent_id: agent },
                { onSuccess: (d) => { onCreated?.(d); onClose(); } },
            );
        } else if (device) {
            update.mutate(
                { id: device.id, name: name.trim(), mgmt_ip: mgmtIp.trim(), poll_method: pollMethod, device_type: deviceType, credential_id: credId, agent_id: agent, monitored },
                { onSuccess: onClose },
            );
        }
    }

    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <form
                onSubmit={submit}
                className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10"
            >
                <div className="space-y-3 rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="mb-2 flex items-start justify-between">
                        <div>
                            <h2 className="text-base font-bold tracking-tight text-white">
                                {mode === 'create' ? 'Add device' : `Edit ${device?.name ?? 'device'}`}
                            </h2>
                            <p className="text-xs text-white/40">
                                {mode === 'create' ? 'It is added to your fleet and placed on this map' : 'Change how this device is identified and polled'}
                            </p>
                        </div>
                        <button type="button" onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Name" className={field} />
                    <input value={mgmtIp} onChange={(e) => setMgmtIp(e.target.value)} placeholder="Management IP" className={field} />
                    <select value={pollMethod} onChange={(e) => changePollMethod(e.target.value as PollMethod)} className={field}>
                        <option value="snmp">SNMP</option>
                        <option value="routeros">RouterOS API</option>
                        <option value="none">Ping only (no throughput)</option>
                    </select>

                    {needsCredential && (
                        matchingCreds.length > 0 ? (
                            <select value={credentialId} onChange={(e) => setCredentialId(e.target.value)} className={field}>
                                <option value="">Select a {pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential</option>
                                {matchingCreds.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        ) : (
                            <p className="px-1 text-xs text-amber-300/80">
                                No {pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential yet - add one under Settings first, or discovery won't have anything to authenticate with.
                            </p>
                        )
                    )}

                    <select value={deviceType} onChange={(e) => setDeviceType(e.target.value as DeviceType)} className={field}>
                        {DEVICE_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>{t.label}</option>
                        ))}
                    </select>

                    {agents && agents.length > 0 && (
                        <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={field}>
                            <option value="">Polled centrally (this server)</option>
                            {agents.map((a) => (
                                <option key={a.id} value={a.id}>Via agent: {a.name}</option>
                            ))}
                        </select>
                    )}

                    {mode === 'edit' && (
                        <div className="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/10">
                            <span className="text-sm text-white/75">Monitoring {monitored ? 'on' : 'paused'}</span>
                            <Toggle checked={monitored} onChange={setMonitored} label="Monitoring" />
                        </div>
                    )}

                    <div className="flex items-center justify-end gap-2.5 pt-1">
                        <button type="button" onClick={onClose} className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors hover:text-white/90">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                        >
                            {busy ? 'Saving...' : mode === 'create' ? 'Add device' : 'Save changes'}
                        </button>
                    </div>

                    {isError && <p className="text-xs text-rose-400/90">{serverError ?? "Couldn't save - check the fields (a valid, routable IP is required)."}</p>}
                </div>
            </form>
        </div>,
        document.body,
    );
}
