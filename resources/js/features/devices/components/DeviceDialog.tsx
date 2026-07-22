import { useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { X } from '@phosphor-icons/react';
import { useCreateDevice } from '../api/createDevice';
import { useUpdateDevice } from '../api/updateDevice';
import { useAgents } from '../../settings/api/agents';
import { useCredentials } from '../../settings/api/credentials';
import { useIsAdmin } from '../../auth/api/auth';
import { useGeocode, useMapConfig } from '../../geo/api/geo';
import { pushToast } from '../../../lib/toast';
import { Toggle } from '../../../components/Toggle';
import { DEVICE_ICONS, DEVICE_ICON_KEYS, ICON_COLORS } from '../../../components/deviceIcons';
import type { Device, DeviceType, PollMethod } from '../../../types';

// Small field label so an operator knows what each input is (placeholders vanish once filled).
const fieldLabel = 'block px-1 text-[11px] font-medium text-white/55';

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
    const [icon, setIcon] = useState<string | null>(device?.icon ?? null);
    const [iconColor, setIconColor] = useState<string | null>(device?.icon_color ?? null);
    // Latency quality thresholds (internet/upstream card). Empty string = use the card default.
    const [latencyGood, setLatencyGood] = useState<string>(device?.latency_good_ms != null ? String(device.latency_good_ms) : '');
    const [latencyBad, setLatencyBad] = useState<string>(device?.latency_bad_ms != null ? String(device.latency_bad_ms) : '');
    // Geographic position (geo overlay).
    const [lat, setLat] = useState<string>(device?.latitude != null ? String(device.latitude) : '');
    const [lng, setLng] = useState<string>(device?.longitude != null ? String(device.longitude) : '');
    const [address, setAddress] = useState('');
    const geocode = useGeocode();
    const { data: mapCfg } = useMapConfig();

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
            const latGood = latencyGood.trim() === '' ? null : Number(latencyGood);
            const latBad = latencyBad.trim() === '' ? null : Number(latencyBad);
            update.mutate(
                {
                    id: device.id, name: name.trim(), mgmt_ip: mgmtIp.trim(), poll_method: pollMethod, device_type: deviceType,
                    credential_id: credId, agent_id: agent, monitored, icon, icon_color: iconColor,
                    latency_good_ms: latGood, latency_bad_ms: latBad,
                    latitude: lat.trim() === '' ? null : Number(lat),
                    longitude: lng.trim() === '' ? null : Number(lng),
                },
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

                    <label className="block space-y-1">
                        <span className={fieldLabel}>Name</span>
                        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. bdr1" className={field} />
                    </label>
                    <label className="block space-y-1">
                        <span className={fieldLabel}>Management IP</span>
                        <input value={mgmtIp} onChange={(e) => setMgmtIp(e.target.value)} placeholder="e.g. 10.0.0.1" className={field} />
                    </label>
                    <label className="block space-y-1">
                        <span className={fieldLabel}>Poll method</span>
                        <select value={pollMethod} onChange={(e) => changePollMethod(e.target.value as PollMethod)} className={field}>
                            <option value="snmp">SNMP</option>
                            <option value="routeros">RouterOS API</option>
                            <option value="none">Ping only (no throughput)</option>
                        </select>
                    </label>

                    {needsCredential && (
                        matchingCreds.length > 0 ? (
                            <label className="block space-y-1">
                                <span className={fieldLabel}>{pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential</span>
                                <select value={credentialId} onChange={(e) => setCredentialId(e.target.value)} className={field}>
                                    <option value="">Select a {pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential</option>
                                    {matchingCreds.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                            </label>
                        ) : (
                            <p className="px-1 text-xs text-amber-300/80">
                                No {pollMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential yet - add one under Settings first, or discovery won't have anything to authenticate with.
                            </p>
                        )
                    )}

                    <label className="block space-y-1">
                        <span className={fieldLabel}>Device type</span>
                        <select value={deviceType} onChange={(e) => setDeviceType(e.target.value as DeviceType)} className={field}>
                            {DEVICE_TYPES.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </select>
                    </label>

                    {agents && agents.length > 0 && (
                        <label className="block space-y-1">
                            <span className={fieldLabel}>Polled by</span>
                            <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={field}>
                                <option value="">Polled centrally (this server)</option>
                                {agents.map((a) => (
                                    <option key={a.id} value={a.id}>Via agent: {a.name}</option>
                                ))}
                            </select>
                        </label>
                    )}

                    {mode === 'edit' && (
                        <div className="flex items-center justify-between rounded-xl bg-white/[0.03] px-3 py-2 ring-1 ring-white/10">
                            <span className="text-sm text-white/75">Monitoring {monitored ? 'on' : 'paused'}</span>
                            <Toggle checked={monitored} onChange={setMonitored} label="Monitoring" />
                        </div>
                    )}

                    {/* Geographic position for the geo overlay. */}
                    {mode === 'edit' && (
                        <div className="space-y-2 rounded-xl bg-white/[0.03] px-3 py-2.5 ring-1 ring-white/10">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-white/55">Location (latitude, longitude)</span>
                                {device?.geo_source && <span className="text-[10px] text-white/35">from {device.geo_source}</span>}
                            </div>
                            <div className="flex gap-2">
                                <label className="flex-1 space-y-1">
                                    <span className="px-0.5 text-[10px] text-white/40">Latitude</span>
                                    <input value={lat} onChange={(e) => setLat(e.target.value)} placeholder="-27.4661" inputMode="decimal" className={field} />
                                </label>
                                <label className="flex-1 space-y-1">
                                    <span className="px-0.5 text-[10px] text-white/40">Longitude</span>
                                    <input value={lng} onChange={(e) => setLng(e.target.value)} placeholder="153.0186" inputMode="decimal" className={field} />
                                </label>
                            </div>
                            {mapCfg?.geocoder_enabled && (
                                <div className="flex gap-2">
                                    <input
                                        value={address}
                                        onChange={(e) => setAddress(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
                                        placeholder="Or find by address"
                                        className={field}
                                    />
                                    <button
                                        type="button"
                                        disabled={address.trim() === '' || geocode.isPending}
                                        onClick={async () => {
                                            const hit = await geocode.mutateAsync(address.trim());
                                            if (hit) { setLat(String(hit.lat)); setLng(String(hit.lng)); } else pushToast({ title: 'No match for that address', tone: 'down' });
                                        }}
                                        className="shrink-0 rounded-xl bg-white/[0.06] px-3 text-sm text-white/80 ring-1 ring-white/10 transition hover:bg-white/10 disabled:opacity-40"
                                    >
                                        {geocode.isPending ? '...' : 'Find'}
                                    </button>
                                </div>
                            )}
                            {(lat.trim() !== '' || lng.trim() !== '') && (
                                <button type="button" onClick={() => { setLat(''); setLng(''); }} className="text-[11px] text-white/45 hover:text-white/80">Clear location</button>
                            )}
                        </div>
                    )}

                    {mode === 'edit' && deviceType === 'internet' && (
                        <div className="space-y-2.5 rounded-xl bg-white/[0.03] px-3 py-2.5 ring-1 ring-white/10">
                            <span className="block text-xs font-medium text-white/55">Latency quality (ms)</span>
                            <div className="flex items-center gap-2">
                                <label className="flex-1 space-y-1">
                                    <span className="px-0.5 text-[11px] text-emerald-300/80">Good at or below</span>
                                    <input
                                        type="number" min={0} inputMode="numeric"
                                        value={latencyGood} onChange={(e) => setLatencyGood(e.target.value)}
                                        placeholder="30" className={field}
                                    />
                                </label>
                                <label className="flex-1 space-y-1">
                                    <span className="px-0.5 text-[11px] text-rose-300/80">Bad at or above</span>
                                    <input
                                        type="number" min={0} inputMode="numeric"
                                        value={latencyBad} onChange={(e) => setLatencyBad(e.target.value)}
                                        placeholder="150" className={field}
                                    />
                                </label>
                            </div>
                            {latencyGood.trim() !== '' && latencyBad.trim() !== '' && Number(latencyGood) > Number(latencyBad) && (
                                <p className="px-0.5 text-[11px] text-amber-300/80">Good should be lower than bad.</p>
                            )}
                            <p className="px-0.5 text-[11px] leading-snug text-white/35">
                                The internet card shows ping latency, green below the good mark and red above the bad. Leave blank for defaults.
                            </p>
                        </div>
                    )}

                    {mode === 'edit' && (
                        <div className="space-y-2.5 rounded-xl bg-white/[0.03] px-3 py-2.5 ring-1 ring-white/10">
                            <span className="block text-xs font-medium text-white/55">Icon (map + sidebar)</span>
                            <div className="grid grid-cols-8 gap-1">
                                <button
                                    type="button"
                                    onClick={() => setIcon(null)}
                                    title="Auto - product photo, vendor mark, or device type"
                                    className={`grid h-8 place-items-center rounded-lg text-[9px] font-semibold ring-1 transition ${icon === null ? 'bg-emerald-500/20 text-emerald-200 ring-emerald-400/40' : 'text-white/50 ring-white/10 hover:bg-white/5'}`}
                                >
                                    Auto
                                </button>
                                {DEVICE_ICON_KEYS.map((key) => {
                                    const I = DEVICE_ICONS[key];
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => setIcon(key)}
                                            title={key}
                                            className={`grid h-8 place-items-center rounded-lg ring-1 transition ${icon === key ? 'bg-emerald-500/20 ring-emerald-400/40' : 'ring-white/10 hover:bg-white/5'}`}
                                        >
                                            <I weight="duotone" className="h-4 w-4" style={{ color: iconColor ?? undefined }} />
                                        </button>
                                    );
                                })}
                            </div>
                            <span className="block text-xs font-medium text-white/55">Colour</span>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => setIconColor(null)}
                                    title="Default colour"
                                    className={`grid h-6 w-6 place-items-center rounded-full bg-white/5 text-[9px] text-white/50 ring-1 ${iconColor === null ? 'ring-white/60' : 'ring-white/15'}`}
                                >
                                    A
                                </button>
                                {ICON_COLORS.map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        onClick={() => setIconColor(c)}
                                        title={c}
                                        className={`h-6 w-6 rounded-full ring-2 transition ${iconColor === c ? 'ring-white' : 'ring-transparent hover:ring-white/30'}`}
                                        style={{ background: c }}
                                    />
                                ))}
                                <input
                                    type="color"
                                    value={iconColor ?? '#34d399'}
                                    onChange={(e) => setIconColor(e.target.value)}
                                    title="Custom colour"
                                    className="h-6 w-6 cursor-pointer rounded-full border-0 bg-transparent p-0 [color-scheme:dark]"
                                />
                            </div>
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
