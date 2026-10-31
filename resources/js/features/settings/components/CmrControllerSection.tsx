import { useEffect, useState } from 'react';
import { Copy, PaperPlaneTilt, PlugsConnected } from '@phosphor-icons/react';
import { Toggle } from '../../../components/Toggle';
import { useCmrSettings, useTestCmrController, useUpdateCmrSettings, type CmrSettingsInput } from '../api/cmrSettings';
import { pushToast } from '../../../lib/toast';

const card = 'min-w-0 rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'placeholder:text-white/30 [color-scheme:dark] ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

function copy(text: string, what: string) {
    navigator.clipboard?.writeText(text);
    pushToast({ title: `${what} copied`, tone: 'up' });
}

/** One "configure this on your device" value with a copy button. */
function DeviceValue({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">{label}</p>
            <div className="mt-1 flex items-start gap-2">
                <span className={`min-w-0 flex-1 break-all text-sm text-white/85 ${mono ? 'font-mono text-xs leading-relaxed' : ''}`}>{value}</span>
                <button onClick={() => copy(value, label)} title={`Copy ${label.toLowerCase()}`} className="shrink-0 rounded-md p-1 text-white/40 hover:bg-white/5 hover:text-white/80">
                    <Copy weight="light" className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}

/**
 * The MikroTik CMR controller (cmr-ctrl sidecar). Shows what to configure on a device - this
 * host, the port and the certificate fingerprint it pins - plus the daemon connection. Paired
 * devices wait in the discovery review queue until an admin adds them.
 */
export function CmrControllerSection() {
    const { data: cfg } = useCmrSettings();
    const update = useUpdateCmrSettings();
    const test = useTestCmrController();
    const [form, setForm] = useState<CmrSettingsInput>({ enabled: false, api_url: 'http://127.0.0.1:8411', public_host: '' });
    const [token, setToken] = useState('');

    useEffect(() => {
        if (cfg) setForm({ enabled: cfg.enabled, api_url: cfg.api_url, public_host: cfg.public_host });
    }, [cfg]);
    const set = <K extends keyof CmrSettingsInput>(k: K, v: CmrSettingsInput[K]) => setForm((f) => ({ ...f, [k]: v }));

    function save() {
        update.mutate(
            { ...form, api_token: token || undefined },
            {
                onSuccess: () => {
                    setToken('');
                    pushToast({ title: 'CMR controller saved', tone: 'info' });
                },
                onError: () => pushToast({ title: 'Couldn\'t save the CMR controller', tone: 'down' }),
            },
        );
    }

    function runTest() {
        test.mutate(undefined, {
            onSuccess: (r) =>
                pushToast(
                    r.ok
                        ? { title: 'CMR controller reachable', tone: 'up' }
                        : { title: 'CMR controller unreachable', detail: 'Check the URL/token and that mymate-cmr is running.', tone: 'down' },
                ),
            onError: () => pushToast({ title: 'CMR controller unreachable', tone: 'down' }),
        });
    }

    const identity = cfg?.identity ?? null;

    return (
        <section className={card}>
            <div className="mb-4 flex items-center gap-2">
                <PlugsConnected weight="light" className="h-4 w-4 text-white/40" />
                <div>
                    <h2 className="text-sm font-bold text-white">CMR controller</h2>
                    <p className="text-xs text-white/40">
                        MikroTik devices in controller-managed mode connect here. Each one waits in Discovery until you add it.
                    </p>
                </div>
            </div>

            <div className="mb-4 space-y-3 rounded-xl bg-white/[0.02] p-3.5 ring-1 ring-white/[0.06]">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-xs font-semibold text-white/70">Point your devices at</p>
                    <span className={`text-[11px] ${identity ? 'text-emerald-300' : 'text-white/40'}`}>
                        {identity ? `Running ${identity.version}` : cfg?.configured ? 'Not reachable' : 'Not set up'}
                    </span>
                </div>
                {identity ? (
                    <>
                        <DeviceValue label="Controller address" value={`${cfg?.effective_host || 'this-host'}:${cfg?.port ?? 54321}`} />
                        <DeviceValue label="Fingerprint" value={identity.fingerprint} mono />
                        <p className="text-[11px] text-white/35">Devices must be able to reach this host on tcp/{cfg?.port ?? 54321}. Only open pairing is supported for now.</p>
                    </>
                ) : (
                    <p className="text-[11px] text-white/40">
                        The controller isn&apos;t answering. On a package install run <span className="font-mono">deploy/cmr/provision.sh</span>; in Docker it starts with the container.
                    </p>
                )}
            </div>

            <div className="space-y-2.5">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-sm text-white/75">Sync paired devices into Discovery</span>
                    <Toggle checked={form.enabled} onChange={(v) => set('enabled', v)} label="Sync paired devices into Discovery" />
                </div>
                <input className={field} placeholder="Admin API URL (http://127.0.0.1:8411)" value={form.api_url} onChange={(e) => set('api_url', e.target.value)} />
                <input
                    className={field}
                    type="password"
                    placeholder={cfg?.api_token_set ? 'Admin token (leave blank to keep)' : 'Admin bearer token'}
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                />
                <input
                    className={field}
                    placeholder={`Address devices use (default: ${cfg?.effective_host || 'this host'})`}
                    value={form.public_host ?? ''}
                    onChange={(e) => set('public_host', e.target.value)}
                />
            </div>

            <div className="mt-4 flex items-center justify-between gap-3">
                <button
                    onClick={runTest}
                    disabled={test.isPending || !cfg?.configured}
                    title={cfg?.configured ? 'Check cmr-ctrl is reachable' : 'Save the URL + token first'}
                    className="flex shrink-0 items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-2 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white disabled:opacity-40"
                >
                    <PaperPlaneTilt weight="bold" className="h-3.5 w-3.5" /> {test.isPending ? 'Testing...' : 'Test connection'}
                </button>
                <button
                    onClick={save}
                    disabled={update.isPending || form.api_url === ''}
                    className="shrink-0 rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Save controller'}
                </button>
            </div>
        </section>
    );
}
