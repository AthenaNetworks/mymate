import { useState } from 'react';
import { Database, Plus, CaretDown, ArrowClockwise } from '@phosphor-icons/react';
import {
    useLibreNmsPreview,
    useLibreNmsImport,
    type LibreNmsDriver,
    type LibreNmsPreview,
    type LibreNmsConnection,
} from '../api/librenms';
import { useCredentials, useSaveCredential } from '../../settings/api/credentials';
import { pushToast } from '../../../lib/toast';

const card = 'rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const field =
    'w-full rounded-lg bg-white/[0.04] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none transition focus:ring-emerald-400/40 placeholder:text-white/30';

function errMsg(e: unknown): string {
    return (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Something went wrong';
}

export function LibreNmsImport() {
    const [driver, setDriver] = useState<LibreNmsDriver>('mysql');
    const [c, setC] = useState({ host: '', port: 3306, database: 'librenms', username: '', password: '', base_url: '', token: '' });
    const [preview, setPreview] = useState<LibreNmsPreview | null>(null);
    const [importCreds, setImportCreds] = useState(true);
    const [importMaps, setImportMaps] = useState(true);
    const [includeDisabled, setIncludeDisabled] = useState(false);
    const [credentialId, setCredentialId] = useState<number | null>(null);
    const [showHelp, setShowHelp] = useState(false);
    const [addingCred, setAddingCred] = useState(false);
    const [newCred, setNewCred] = useState({ name: '', community: '' });

    const previewM = useLibreNmsPreview();
    const importM = useLibreNmsImport();
    const saveCred = useSaveCredential();
    const { data: credentials } = useCredentials();
    const set = <K extends keyof typeof c>(k: K, v: (typeof c)[K]) => setC((p) => ({ ...p, [k]: v }));

    const connection = (): LibreNmsConnection =>
        driver === 'mysql'
            ? { driver, host: c.host, port: c.port, database: c.database, username: c.username, password: c.password }
            : { driver, base_url: c.base_url, token: c.token };

    // Whether the operator must choose a credential (API import, or MySQL without importing creds).
    const needsCredential = driver === 'api' || !importCreds;

    function runPreview() {
        previewM.mutate(connection(), {
            onSuccess: setPreview,
            onError: (e) => pushToast({ title: "Couldn't reach LibreNMS", detail: errMsg(e), tone: 'down' }),
        });
    }

    function runImport() {
        importM.mutate(
            {
                ...connection(),
                import_credentials: driver === 'mysql' && importCreds,
                import_maps: driver === 'mysql' && importMaps,
                include_disabled: includeDisabled,
                credential_id: credentialId,
            },
            {
                onSuccess: (s) => {
                    pushToast({
                        title: `Imported ${s.devices.created} new, ${s.devices.updated} updated`,
                        detail: `${s.credentials} credential(s), ${s.maps} map(s), ${s.positions} placement(s)`,
                        tone: 'info',
                    });
                    setPreview(null);
                },
                onError: (e) => pushToast({ title: 'Import failed', detail: errMsg(e), tone: 'down' }),
            },
        );
    }

    async function quickAddCredential() {
        try {
            const cred = await saveCred.mutateAsync({ name: newCred.name || `LibreNMS ${newCred.community}`, type: 'snmp', snmp_community: newCred.community });
            setCredentialId(cred.id);
            setAddingCred(false);
            setNewCred({ name: '', community: '' });
        } catch {
            pushToast({ title: "Couldn't add the credential", tone: 'down' });
        }
    }

    return (
        <section className={card}>
            <h2 className="flex items-center gap-2 text-sm font-bold text-white">
                <Database weight="duotone" className="h-4 w-4 text-sky-300" /> Import from LibreNMS
            </h2>
            <p className="mt-1 text-xs text-white/45">
                Bring devices across from LibreNMS. <span className="text-white/70">MySQL</span> imports credentials and maps
                too; the <span className="text-white/70">API</span> imports devices and you map them to a credential here.
            </p>

            {/* Driver */}
            <div className="mt-4 grid grid-cols-2 gap-2">
                {(['mysql', 'api'] as LibreNmsDriver[]).map((d) => (
                    <button
                        key={d}
                        type="button"
                        onClick={() => { setDriver(d); setPreview(null); }}
                        className={`rounded-xl px-4 py-2.5 text-left text-sm ring-1 transition ${
                            driver === d ? 'bg-sky-500/10 ring-sky-400/40' : 'bg-white/[0.02] ring-white/10 hover:bg-white/[0.04]'
                        }`}
                    >
                        <div className="font-semibold text-white">{d === 'mysql' ? 'MySQL (full)' : 'API (devices only)'}</div>
                        <div className="mt-0.5 text-[11px] text-white/45">{d === 'mysql' ? 'Credentials + maps' : 'Map to a credential'}</div>
                    </button>
                ))}
            </div>

            {/* Connection */}
            <div className="mt-3 space-y-2">
                {driver === 'mysql' ? (
                    <>
                        <div className="flex gap-2">
                            <input className={field} placeholder="MySQL host (LibreNMS DB)" value={c.host} onChange={(e) => set('host', e.target.value)} />
                            <input className={`${field} w-24`} type="number" placeholder="3306" value={c.port} onChange={(e) => set('port', Number(e.target.value))} />
                        </div>
                        <input className={field} placeholder="Database (librenms)" value={c.database} onChange={(e) => set('database', e.target.value)} />
                        <div className="flex gap-2">
                            <input className={field} placeholder="Username" value={c.username} onChange={(e) => set('username', e.target.value)} />
                            <input className={field} type="password" placeholder="Password" value={c.password} onChange={(e) => set('password', e.target.value)} />
                        </div>
                        <button onClick={() => setShowHelp((s) => !s)} className="flex items-center gap-1 text-[11px] text-sky-300/80 hover:text-sky-300">
                            <CaretDown weight="bold" className={`h-3 w-3 transition-transform ${showHelp ? 'rotate-180' : ''}`} /> How to set up MySQL access
                        </button>
                        {showHelp && (
                            <div className="rounded-lg bg-black/20 p-3 text-[11px] leading-relaxed text-white/50 ring-1 ring-white/[0.06]">
                                On the LibreNMS server, create a read-only user this instance can reach:
                                <pre className="mt-1.5 overflow-x-auto whitespace-pre text-[10px] text-white/70">{`CREATE USER 'mymate'@'%' IDENTIFIED BY 'a-strong-password';
GRANT SELECT ON librenms.* TO 'mymate'@'%';
FLUSH PRIVILEGES;`}</pre>
                                MySQL must accept the connection: set <span className="font-mono text-white/70">bind-address = 0.0.0.0</span> in
                                <span className="font-mono text-white/70"> my.cnf</span> (or bind to this server's subnet) and open port 3306 to My Mate.
                                Replace <span className="font-mono text-white/70">'%'</span> with My Mate's IP to lock it down.
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        <input className={field} placeholder="LibreNMS URL (https://librenms.example)" value={c.base_url} onChange={(e) => set('base_url', e.target.value)} />
                        <input className={field} type="password" placeholder="API token (Settings -> API in LibreNMS)" value={c.token} onChange={(e) => set('token', e.target.value)} />
                    </>
                )}

                <button
                    onClick={runPreview}
                    disabled={previewM.isPending}
                    className="flex w-full items-center justify-center gap-2 rounded-xl bg-white/[0.05] px-4 py-2 text-sm font-medium text-white/85 ring-1 ring-white/10 transition hover:bg-white/[0.08] disabled:opacity-40"
                >
                    <ArrowClockwise weight="bold" className={`h-4 w-4 ${previewM.isPending ? 'animate-spin' : ''}`} />
                    {previewM.isPending ? 'Connecting...' : preview ? 'Refresh preview' : 'Preview'}
                </button>
            </div>

            {/* Preview + options */}
            {preview && (
                <div className="mt-4 space-y-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-white/60">
                        <span className="font-semibold text-white/85">{preview.device_count} devices</span>
                        {driver === 'mysql' && <span>{preview.with_community} with a community</span>}
                        {preview.maps.length > 0 && <span>{preview.maps.length} map(s)</span>}
                    </div>

                    <div className="max-h-40 space-y-0.5 overflow-y-auto rounded-lg bg-black/20 p-1.5 ring-1 ring-white/[0.06]">
                        {preview.devices.slice(0, 200).map((d, i) => (
                            <div key={i} className="flex items-center justify-between gap-2 px-2 py-1 text-xs">
                                <span className="truncate text-white/75">{d.sysname || d.hostname}</span>
                                <span className="shrink-0 font-mono text-[10px] text-white/40">{d.ip ?? 'no ip'}{d.os ? ` · ${d.os}` : ''}</span>
                            </div>
                        ))}
                    </div>

                    {/* Options */}
                    <div className="space-y-2 text-sm text-white/70">
                        {driver === 'mysql' && (
                            <>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={importCreds} onChange={(e) => setImportCreds(e.target.checked)} /> Import SNMP credentials from LibreNMS
                                </label>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={importMaps} onChange={(e) => setImportMaps(e.target.checked)} /> Import maps + device positions
                                </label>
                            </>
                        )}
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={includeDisabled} onChange={(e) => setIncludeDisabled(e.target.checked)} /> Include disabled devices
                        </label>
                    </div>

                    {/* Credential to apply to all (required for API / when not importing creds) */}
                    {needsCredential && (
                        <div className="rounded-lg bg-white/[0.02] p-3 ring-1 ring-white/[0.06]">
                            <p className="mb-2 text-[11px] font-medium text-white/45">Apply this credential to all imported devices</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <select
                                    value={credentialId ?? ''}
                                    onChange={(e) => setCredentialId(e.target.value ? Number(e.target.value) : null)}
                                    className={`${field} flex-1`}
                                >
                                    <option value="">No credential (ping-only)</option>
                                    {(credentials ?? []).map((cr) => (
                                        <option key={cr.id} value={cr.id}>
                                            {cr.name} ({cr.type})
                                        </option>
                                    ))}
                                </select>
                                <button onClick={() => setAddingCred((s) => !s)} className="flex items-center gap-1 rounded-lg bg-white/[0.05] px-3 py-2 text-xs text-white/70 ring-1 ring-white/10 hover:text-white">
                                    <Plus weight="bold" className="h-3.5 w-3.5" /> New
                                </button>
                            </div>
                            {addingCred && (
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <input className={`${field} flex-1`} placeholder="Name" value={newCred.name} onChange={(e) => setNewCred((p) => ({ ...p, name: e.target.value }))} />
                                    <input className={`${field} flex-1`} placeholder="SNMP community" value={newCred.community} onChange={(e) => setNewCred((p) => ({ ...p, community: e.target.value }))} />
                                    <button
                                        onClick={quickAddCredential}
                                        disabled={!newCred.community || saveCred.isPending}
                                        className="rounded-lg bg-emerald-500/90 px-3 py-2 text-xs font-semibold text-emerald-950 hover:bg-emerald-400 disabled:opacity-40"
                                    >
                                        Add
                                    </button>
                                </div>
                            )}
                        </div>
                    )}

                    <button
                        onClick={runImport}
                        disabled={importM.isPending}
                        className="w-full rounded-xl bg-emerald-500/90 px-4 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 disabled:opacity-40"
                    >
                        {importM.isPending ? 'Importing...' : `Import ${preview.device_count} devices`}
                    </button>
                </div>
            )}
        </section>
    );
}
