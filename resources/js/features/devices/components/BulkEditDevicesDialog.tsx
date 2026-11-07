import { useState, type FormEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { PencilSimple, X } from '@phosphor-icons/react';
import { bulkErrorMessages, useBulkUpdateDevices, type BulkDeviceChanges } from '../api/bulkUpdateDevices';
import { useDevicesByIds } from '../api/getDevices';
import { useCredentials } from '../../settings/api/credentials';
import { useAgents } from '../../settings/api/agents';
import { useGeocode, useMapConfig } from '../../geo/api/geo';
import { DevicePicker, type PickedDevice } from '../../../components/DevicePicker';
import { DeviceIconPicker } from './DeviceIconPicker';
import { pushToast } from '../../../lib/toast';
import type { Device, DeviceType, PollMethod } from '../../../types';

const KEEP = 'keep';
type Keep = typeof KEEP;

const DEVICE_TYPES: { value: DeviceType; label: string }[] = [
    { value: 'unknown', label: 'Auto-detect type' },
    { value: 'router', label: 'Router' },
    { value: 'switch', label: 'Switch' },
    { value: 'ap', label: 'Access point' },
    { value: 'server', label: 'Server' },
    { value: 'internet', label: 'Internet / upstream' },
];
const POLL_METHODS: { value: PollMethod; label: string }[] = [
    { value: 'snmp', label: 'SNMP' },
    { value: 'routeros', label: 'RouterOS API' },
    { value: 'none', label: 'Ping only (no throughput)' },
];

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 placeholder:text-white/30 [color-scheme:dark] focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';
const label = 'text-[11px] font-medium uppercase tracking-wide text-white/40';
const MAX_ERRORS_SHOWN = 12;

/**
 * Edit many devices at once - opened from the Devices list's selection and from a multi-select on
 * the map. Every field starts at "Leave as is" and only the ones changed are sent, so devices keep
 * whatever differs between them. The server applies it all or nothing; if any device can't take
 * the change, nothing is saved and each problem is listed here by device.
 */
export function BulkEditDevicesDialog({ deviceIds, onClose, onSaved }: { deviceIds: number[]; onClose: () => void; onSaved?: () => void }) {
    const bulk = useBulkUpdateDevices();
    const { data: devices } = useDevicesByIds(deviceIds, 'full');
    const { data: credentials } = useCredentials();
    const { data: agents } = useAgents();
    const geocode = useGeocode();
    const { data: mapCfg } = useMapConfig();
    const count = deviceIds.length;

    const [pollMethod, setPollMethod] = useState<Keep | PollMethod>(KEEP);
    const [pollCred, setPollCred] = useState<Keep | string>(KEEP); // '' = none, else a credential id
    const [deviceType, setDeviceType] = useState<Keep | DeviceType>(KEEP);
    const [monitored, setMonitored] = useState<Keep | 'live' | 'paused'>(KEEP);
    const [agent, setAgent] = useState<Keep | string>(KEEP); // '' = central
    const [parentMode, setParentMode] = useState<Keep | 'set' | 'clear'>(KEEP);
    const [parent, setParent] = useState<PickedDevice | null>(null);
    const [sshCred, setSshCred] = useState<Keep | string>(KEEP);
    const [rosCred, setRosCred] = useState<Keep | string>(KEEP);
    const [pingMode, setPingMode] = useState<Keep | 'set' | 'clear'>(KEEP);
    const [pingSource, setPingSource] = useState('');
    const [locMode, setLocMode] = useState<Keep | 'set' | 'clear'>(KEEP);
    const [lat, setLat] = useState('');
    const [lng, setLng] = useState('');
    const [address, setAddress] = useState('');
    const [icon, setIcon] = useState<Keep | string | null>(KEEP); // null = Auto
    const [iconColor, setIconColor] = useState<Keep | string | null>(KEEP); // null = default colour
    const [errors, setErrors] = useState<string[]>([]);

    const creds = credentials ?? [];
    const credsOf = (type: string) => creds.filter((c) => c.type === type);
    // A poll credential on its own only makes sense when every device is polled the same way.
    const methods = [...new Set((devices ?? []).map((d) => d.poll_method))];
    const sharedMethod = devices && devices.length === count && methods.length === 1 ? methods[0] : null;
    const credMethod: PollMethod | null = pollMethod !== KEEP ? pollMethod : sharedMethod;

    function changePollMethod(m: Keep | PollMethod) {
        setPollMethod(m);
        setPollCred(m === KEEP ? KEEP : ''); // a new method needs its credential picked again
    }

    const latNum = Number(lat);
    const lngNum = Number(lng);
    const locValid = lat.trim() !== '' && lng.trim() !== '' && Number.isFinite(latNum) && Number.isFinite(lngNum) && Math.abs(latNum) <= 90 && Math.abs(lngNum) <= 180;

    const changes: BulkDeviceChanges = {};
    if (pollMethod !== KEEP) {
        changes.poll_method = pollMethod;
        if (pollMethod !== 'none') changes.credential_id = pollCred === KEEP || pollCred === '' ? null : Number(pollCred);
    } else if (pollCred !== KEEP) {
        changes.credential_id = pollCred === '' ? null : Number(pollCred);
    }
    if (deviceType !== KEEP) changes.device_type = deviceType;
    if (monitored !== KEEP) changes.monitored = monitored === 'live';
    if (agent !== KEEP) changes.agent_id = agent === '' ? null : Number(agent);
    if (parentMode === 'clear') changes.parent_device_id = null;
    if (parentMode === 'set' && parent) changes.parent_device_id = parent.id;
    if (sshCred !== KEEP) changes.ssh_credential_id = sshCred === '' ? null : Number(sshCred);
    if (rosCred !== KEEP) changes.routeros_credential_id = rosCred === '' ? null : Number(rosCred);
    if (pingMode === 'clear') changes.ping_source = null;
    if (pingMode === 'set' && pingSource.trim() !== '') changes.ping_source = pingSource.trim();
    if (locMode === 'clear') Object.assign(changes, { latitude: null, longitude: null });
    if (locMode === 'set' && locValid) Object.assign(changes, { latitude: latNum, longitude: lngNum });
    if (icon !== KEEP) changes.icon = icon;
    if (iconColor !== KEEP) changes.icon_color = iconColor;

    const incomplete = (parentMode === 'set' && !parent) || (pingMode === 'set' && pingSource.trim() === '') || (locMode === 'set' && !locValid);
    const changeCount = Object.keys(changes).filter((k) => k !== 'longitude' && !(k === 'credential_id' && 'poll_method' in changes)).length;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (changeCount === 0 || incomplete) return;
        setErrors([]);
        bulk.mutate(
            { ids: deviceIds, changes },
            {
                onSuccess: (n) => {
                    pushToast({ title: `Updated ${n} device${n === 1 ? '' : 's'}`, tone: 'up' });
                    onSaved?.();
                    onClose();
                },
                onError: (err) => setErrors(bulkErrorMessages(err)),
            },
        );
    }

    const names = (devices ?? []).map((d) => d.name);
    const credName = (id: number | null) => (id === null ? 'None' : (creds.find((c) => c.id === id)?.name ?? `#${id}`));

    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="animate-rise relative w-full max-w-lg rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="max-h-[85vh] overflow-y-auto rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <header className="mb-4 flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <PencilSimple weight="light" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-base font-bold tracking-tight text-white">Edit {count} device{count === 1 ? '' : 's'}</h2>
                                <p className="truncate text-xs text-white/40" title={names.join(', ')}>
                                    {names.length === 0 ? 'Loading...' : names.slice(0, 4).join(', ') + (names.length > 4 ? ` and ${names.length - 4} more` : '')}
                                </p>
                            </div>
                        </div>
                        <button onClick={onClose} className="rounded-lg p-1 text-white/40 transition-colors hover:bg-white/5 hover:text-white/80">
                            <X weight="bold" className="h-4 w-4" />
                        </button>
                    </header>
                    <p className="mb-4 text-xs text-white/45">Only the fields you change are saved. Everything left as is stays different per device.</p>

                    <form onSubmit={submit} className="space-y-3">
                        <Row title="Poll method" current={summarize(devices, count, (d) => d.poll_method, (m) => POLL_METHODS.find((p) => p.value === m)?.label ?? m)} changed={pollMethod !== KEEP}>
                            <select value={pollMethod} onChange={(e) => changePollMethod(e.target.value as Keep | PollMethod)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                {POLL_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                            </select>
                        </Row>

                        {credMethod !== null && credMethod !== 'none' && (
                            <Row
                                title={`${credMethod === 'snmp' ? 'SNMP' : 'RouterOS'} credential`}
                                current={summarize(devices, count, (d) => d.credential_id, credName)}
                                changed={pollCred !== KEEP}
                            >
                                <select value={pollCred} onChange={(e) => setPollCred(e.target.value)} className={field}>
                                    {pollMethod === KEEP && <option value={KEEP}>Leave as is</option>}
                                    <option value="">None</option>
                                    {credsOf(credMethod).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </Row>
                        )}
                        {credMethod === null && devices && (
                            <p className="px-1 text-[11px] text-white/35">The poll credential can only be changed when all the selected devices use the same poll method, or when you change the poll method too.</p>
                        )}

                        <Row title="Type" current={summarize(devices, count, (d) => d.device_type, (t) => DEVICE_TYPES.find((x) => x.value === t)?.label ?? t)} changed={deviceType !== KEEP}>
                            <select value={deviceType} onChange={(e) => setDeviceType(e.target.value as Keep | DeviceType)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                {DEVICE_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </select>
                        </Row>

                        <Row title="Monitoring" current={summarize(devices, count, (d) => d.monitored, (m) => (m ? 'Live' : 'Paused'))} changed={monitored !== KEEP}>
                            <select value={monitored} onChange={(e) => setMonitored(e.target.value as typeof monitored)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="live">Live - polled</option>
                                <option value="paused">Paused - not polled</option>
                            </select>
                        </Row>

                        {agents && agents.length > 0 && (
                            <Row
                                title="Polled by"
                                current={summarize(devices, count, (d) => d.agent_id, (id) => (id === null ? 'This server' : (agents.find((a) => a.id === id)?.name ?? `Agent #${id}`)))}
                                changed={agent !== KEEP}
                            >
                                <select value={agent} onChange={(e) => setAgent(e.target.value)} className={field}>
                                    <option value={KEEP}>Leave as is</option>
                                    <option value="">Polled centrally (this server)</option>
                                    {agents.map((a) => <option key={a.id} value={a.id}>Via agent: {a.name}</option>)}
                                </select>
                            </Row>
                        )}

                        <Row title="Parent (upstream device)" current={summarize(devices, count, (d) => d.parent_name ?? null, (n) => n ?? 'None')} changed={parentMode !== KEEP}>
                            <select value={parentMode} onChange={(e) => setParentMode(e.target.value as typeof parentMode)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="set">Set to...</option>
                                <option value="clear">Clear (no parent)</option>
                            </select>
                            {parentMode === 'set' && <DevicePicker value={parent} onChange={setParent} excludeId={count === 1 ? deviceIds[0] : undefined} className={field} />}
                        </Row>

                        <Row title="SSH credential (backups)" current={summarize(devices, count, (d) => d.ssh_credential_id, credName)} changed={sshCred !== KEEP}>
                            <select value={sshCred} onChange={(e) => setSshCred(e.target.value)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="">None - fall back to the poll credential</option>
                                {credsOf('ssh').map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Row>

                        <Row title="RouterOS API credential (OSPF)" current={summarize(devices, count, (d) => d.routeros_credential_id, credName)} changed={rosCred !== KEEP}>
                            <select value={rosCred} onChange={(e) => setRosCred(e.target.value)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="">None</option>
                                {credsOf('routeros').map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Row>

                        <Row title="Ping source" current={summarize(devices, count, (d) => d.ping_source ?? null, (s) => s ?? 'Default')} changed={pingMode !== KEEP}>
                            <select value={pingMode} onChange={(e) => setPingMode(e.target.value as typeof pingMode)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="set">Ping from...</option>
                                <option value="clear">Use the default source</option>
                            </select>
                            {pingMode === 'set' && (
                                <input value={pingSource} onChange={(e) => setPingSource(e.target.value)} placeholder="Local address, e.g. 192.0.2.10" className={field} />
                            )}
                        </Row>

                        <Row
                            title="Location (lat, lng)"
                            current={summarize(devices, count, (d) => (d.latitude != null && d.longitude != null ? `${d.latitude}, ${d.longitude}` : null), (v) => v ?? 'Not set')}
                            changed={locMode !== KEEP}
                        >
                            <select value={locMode} onChange={(e) => setLocMode(e.target.value as typeof locMode)} className={field}>
                                <option value={KEEP}>Leave as is</option>
                                <option value="set">Put them all at...</option>
                                <option value="clear">Clear the location</option>
                            </select>
                            {locMode === 'set' && (
                                <>
                                    <div className="flex gap-2">
                                        <input value={lat} onChange={(e) => setLat(e.target.value)} placeholder="Latitude" inputMode="decimal" className={field} />
                                        <input value={lng} onChange={(e) => setLng(e.target.value)} placeholder="Longitude" inputMode="decimal" className={field} />
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
                                    {(lat.trim() !== '' || lng.trim() !== '') && !locValid && <p className="px-1 text-[11px] text-amber-300/80">Enter a latitude (-90 to 90) and longitude (-180 to 180).</p>}
                                    <p className="px-1 text-[11px] text-white/35">Every selected device gets this exact point, as a manual pin.</p>
                                </>
                            )}
                        </Row>

                        <Row
                            title="Icon and colour"
                            current={summarize(devices, count, (d) => `${d.icon ?? 'Auto'} / ${d.icon_color ?? 'default'}`, (v) => v)}
                            changed={icon !== KEEP || iconColor !== KEEP}
                            onReset={icon !== KEEP || iconColor !== KEEP ? () => { setIcon(KEEP); setIconColor(KEEP); } : undefined}
                        >
                            <DeviceIconPicker
                                icon={icon === KEEP ? null : icon}
                                iconColor={iconColor === KEEP ? null : iconColor}
                                iconSet={icon !== KEEP}
                                colorSet={iconColor !== KEEP}
                                onIconChange={setIcon}
                                onColorChange={setIconColor}
                            />
                            <p className="px-1 text-[11px] text-white/35">Pick an icon, a colour, or both. Whatever you don't pick is left as is.</p>
                        </Row>

                        {errors.length > 0 && (
                            <div className="space-y-1 rounded-xl bg-rose-500/10 px-3 py-2.5 ring-1 ring-rose-400/20">
                                <p className="text-xs font-semibold text-rose-200">Nothing was changed:</p>
                                <ul className="list-disc space-y-0.5 pl-4 text-xs text-rose-200/80">
                                    {errors.slice(0, MAX_ERRORS_SHOWN).map((m, i) => <li key={i}>{m}</li>)}
                                </ul>
                                {errors.length > MAX_ERRORS_SHOWN && <p className="text-[11px] text-rose-200/60">and {errors.length - MAX_ERRORS_SHOWN} more</p>}
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-2.5 pt-1">
                            <button type="button" onClick={onClose} className="rounded-full px-4 py-2 text-sm font-medium text-white/60 transition-colors hover:text-white/90">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={bulk.isPending || changeCount === 0 || incomplete}
                                className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                            >
                                {bulk.isPending ? 'Saving...' : `Apply to ${count} device${count === 1 ? '' : 's'}`}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>,
        document.body,
    );
}

/** One field: its title, what the selection has now, and a marker once it's set to change. */
function Row({ title, current, changed, onReset, children }: { title: string; current: string; changed: boolean; onReset?: () => void; children: ReactNode }) {
    return (
        <div className={`space-y-2 rounded-xl px-3 py-2.5 ring-1 ${changed ? 'bg-emerald-500/[0.05] ring-emerald-400/25' : 'bg-white/[0.02] ring-white/10'}`}>
            <div className="flex items-baseline justify-between gap-3">
                <span className={label}>{title}</span>
                <span className="flex min-w-0 items-baseline gap-2">
                    <span className="truncate text-[11px] text-white/35" title={current}>Now: {current}</span>
                    {onReset && (
                        <button type="button" onClick={onReset} className="shrink-0 text-[11px] text-white/45 hover:text-white/80">
                            Leave as is
                        </button>
                    )}
                </span>
            </div>
            {children}
        </div>
    );
}

/** The selection's current value for a field: the shared value, "Mixed", or loading. */
function summarize<T>(devices: Device[] | undefined, count: number, get: (d: Device) => T, show: (v: T) => string): string {
    if (!devices || devices.length < count) return '...';
    const values = [...new Set(devices.map((d) => JSON.stringify(get(d) ?? null)))];
    return values.length === 1 ? show(get(devices[0])) : `Mixed (${values.length})`;
}
