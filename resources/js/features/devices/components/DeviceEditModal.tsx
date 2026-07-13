import { useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { X, PencilSimple } from '@phosphor-icons/react';
import { useUpdateDevice } from '../api/updateDevice';
import { useCredentials } from '../../settings/api/credentials';
import { useDevices } from '../api/getDevices';
import { pushToast } from '../../../lib/toast';
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
    'transition duration-300 placeholder:text-white/30 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

const label = 'block text-[11px] font-medium uppercase tracking-wide text-white/40';

/** Full device edit form in a modal - every field in one place, incl. the monitored
 *  (enable/disable) toggle that pauses/resumes throughput + metrics polling. */
export function DeviceEditModal({ device, onClose }: { device: Device; onClose: () => void }) {
    const update = useUpdateDevice();
    const { data: credentials } = useCredentials();
    const { data: devices } = useDevices();

    const [name, setName] = useState(device.name);
    const [mgmtIp, setMgmtIp] = useState(device.mgmt_ip);
    const [pollMethod, setPollMethod] = useState<PollMethod>(device.poll_method);
    const [deviceType, setDeviceType] = useState<DeviceType>(device.device_type);
    const [credentialId, setCredentialId] = useState<string>(device.credential_id != null ? String(device.credential_id) : '');
    const [parentId, setParentId] = useState<string>(device.parent_device_id != null ? String(device.parent_device_id) : '');
    const [monitored, setMonitored] = useState<boolean>(device.monitored);

    const needsCredential = pollMethod !== 'none';
    const matchingCreds = (credentials ?? []).filter((c) => c.type === pollMethod);
    const parentOptions = (devices ?? []).filter((d) => d.id !== device.id); // a device can't be its own parent

    function changePollMethod(m: PollMethod) {
        setPollMethod(m);
        // Drop a credential of the old type so we never submit e.g. RouterOS creds on SNMP.
        if (m === 'none' || (credentialId !== '' && !matchingCreds.some((c) => String(c.id) === credentialId))) {
            setCredentialId('');
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!name.trim() || !mgmtIp.trim()) return;
        update.mutate(
            {
                id: device.id,
                name: name.trim(),
                mgmt_ip: mgmtIp.trim(),
                poll_method: pollMethod,
                device_type: deviceType,
                monitored,
                credential_id: needsCredential && credentialId !== '' ? Number(credentialId) : null,
                parent_device_id: parentId === '' ? null : Number(parentId),
            },
            {
                onSuccess: () => { pushToast({ title: 'Device saved', tone: 'up' }); onClose(); },
                onError: () => pushToast({ title: "Couldn't save the device", detail: 'Check the fields (a valid IP is required).', tone: 'down' }),
            },
        );
    }

    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-md rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="max-h-[85vh] overflow-y-auto rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <header className="mb-5 flex items-start justify-between">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <PencilSimple weight="light" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold tracking-tight text-white">Edit device</h2>
                                <p className="text-xs text-white/40">{device.name}</p>
                            </div>
                        </div>
                        <button onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>

                    <form onSubmit={submit} className="space-y-3.5">
                        <label className="space-y-1 block">
                            <span className={label}>Name</span>
                            <input value={name} onChange={(e) => setName(e.target.value)} className={field} placeholder="Name" />
                        </label>
                        <label className="space-y-1 block">
                            <span className={label}>Management IP</span>
                            <input value={mgmtIp} onChange={(e) => setMgmtIp(e.target.value)} className={field} placeholder="Management IP" />
                        </label>
                        <label className="space-y-1 block">
                            <span className={label}>Poll method</span>
                            <select value={pollMethod} onChange={(e) => changePollMethod(e.target.value as PollMethod)} className={field}>
                                <option value="snmp">SNMP</option>
                                <option value="routeros">RouterOS API</option>
                                <option value="none">Ping only (no throughput)</option>
                            </select>
                        </label>

                        {needsCredential && (
                            <label className="space-y-1 block">
                                <span className={label}>Credential</span>
                                {matchingCreds.length > 0 ? (
                                    <select value={credentialId} onChange={(e) => setCredentialId(e.target.value)} className={field}>
                                        <option value="">None</option>
                                        {matchingCreds.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                ) : (
                                    <p className="px-1 text-xs text-amber-300/80">
                                        No {pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential yet - add one under Settings.
                                    </p>
                                )}
                            </label>
                        )}

                        <label className="space-y-1 block">
                            <span className={label}>Type</span>
                            <select value={deviceType} onChange={(e) => setDeviceType(e.target.value as DeviceType)} className={field}>
                                {DEVICE_TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </select>
                        </label>

                        <label className="space-y-1 block">
                            <span className={label}>Parent (upstream device)</span>
                            <select value={parentId} onChange={(e) => setParentId(e.target.value)} className={field}>
                                <option value="">None</option>
                                {parentOptions.map((d) => (
                                    <option key={d.id} value={d.id}>{d.name}</option>
                                ))}
                            </select>
                        </label>

                        {/* Enable/disable monitoring. */}
                        <div className="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2.5 ring-1 ring-white/10">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium text-white/85">Monitored</span>
                                <span className="block text-[11px] text-white/40">{monitored ? 'Polling throughput + metrics' : 'Paused - not polled'}</span>
                            </span>
                            <button
                                type="button"
                                role="switch"
                                aria-checked={monitored}
                                onClick={() => setMonitored((m) => !m)}
                                className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${monitored ? 'bg-emerald-500' : 'bg-white/15'}`}
                            >
                                <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-transform ${monitored ? 'translate-x-5' : 'translate-x-0.5'}`} />
                            </button>
                        </div>

                        <div className="flex items-center justify-end gap-2.5 pt-1">
                            <button type="button" onClick={onClose} className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors hover:text-white/90">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={update.isPending || !name.trim() || !mgmtIp.trim()}
                                className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                            >
                                {update.isPending ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>,
        document.body,
    );
}
