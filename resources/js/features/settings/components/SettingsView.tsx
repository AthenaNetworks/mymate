import { useEffect, useState } from 'react';
import { ArrowsClockwise, ArrowRight, Broadcast, Check, CloudArrowDown, Copy, Envelope, Eye, EyeSlash, FloppyDisk, GearSix, Info, Key, LockKey, PaperPlaneTilt, Plus, PencilSimple, ShieldCheck, Trash, UsersThree } from '@phosphor-icons/react';
import { useSettings, useUpdateSettings } from '../api/getSettings';
import { useUpdateCheck } from '../api/updateCheck';
import { useSystemStatus, type StatusLevel } from '../api/systemStatus';
import { SensorsSection } from './SensorsSection';
import { useCredentials, useSaveCredential, useDeleteCredential, type CredentialInput } from '../api/credentials';
import { useMailSettings, useUpdateMailSettings, useTestMail, type MailSettingsInput } from '../api/mailSettings';
import { useBackupSettings, useUpdateBackupSettings, useTestBackupEngine, type BackupSettingsInput } from '../api/backupSettings';
import { useAgents, useEnrolAgent, useDeleteAgent } from '../api/agents';
import { useFactoryReset } from '../api/factoryReset';
import { useUsers, useSaveUser, useDeleteUser, type UserInput } from '../api/users';
import { useMaps } from '../../maps/api/maps';
import { useCurrentUser, useIsAdmin, useUpdatePassword } from '../../auth/api/auth';
import { ConfirmDialog } from '../../../components/Dialog';
import { pushToast } from '../../../lib/toast';
import type { Credential, Operator } from '../../../types';

const field =
    'w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none ' +
    // [color-scheme:dark] makes native controls (the <select> option popup, number spinners)
    // render dark - without it the browser draws the option list white-on-white in dark mode.
    'placeholder:text-white/30 [color-scheme:dark] ' +
    'transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

// Narrow numeric input - NOT `field` (its w-full would stretch full width and crush the label).
const numField =
    'w-24 shrink-0 rounded-xl bg-white/[0.03] px-3 py-2 text-right text-sm tabular-nums text-white ring-1 ring-white/10 ' +
    'outline-none transition duration-300 ease-fluid focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

// min-w-0: every section is a direct grid item below - without it, a grid item defaults
// to min-width:auto (its content\'s min-content size), which can blow the column wider than
// the viewport regardless of internal truncation.
const card = 'min-w-0 rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';

function EngineSettings() {
    const { data: settings } = useSettings();
    const update = useUpdateSettings();
    const [draft, setDraft] = useState<Record<string, number>>({});

    function save() {
        if (!settings) return;
        const payload = settings.map((s) => ({ key: s.key, value: draft[s.key] ?? s.value }));
        update.mutate(payload, {
            onSuccess: () => {
                setDraft({});
                pushToast({ title: 'Settings saved', detail: 'Applies within one loop cycle.', tone: 'info' });
            },
            onError: () => pushToast({ title: 'Couldn\'t save settings', tone: 'down' }),
        });
    }

    return (
        <section className={card}>
            <h2 className="mb-1 text-sm font-bold text-white">Engine cadence &amp; retention</h2>
            <p className="mb-4 text-xs text-white/40">Changes apply within one loop cycle - no worker restart needed.</p>
            <div className="space-y-3">
                {settings?.map((s) => (
                    <label key={s.key} className="flex items-center justify-between gap-4">
                        <span className="min-w-0 flex-1 text-sm text-white/70">{s.label}</span>
                        <input
                            type="number"
                            min={s.min}
                            max={s.max}
                            value={draft[s.key] ?? s.value}
                            onChange={(e) => setDraft((d) => ({ ...d, [s.key]: Number(e.target.value) }))}
                            className={numField}
                        />
                    </label>
                ))}
            </div>
            <div className="mt-4 flex justify-end">
                <button
                    onClick={save}
                    disabled={update.isPending}
                    className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Save settings'}
                </button>
            </div>
        </section>
    );
}

function MailServerSection() {
    const { data: mail } = useMailSettings();
    const update = useUpdateMailSettings();
    const test = useTestMail();
    const [form, setForm] = useState<MailSettingsInput>({ host: '', port: 587, encryption: 'tls', username: '', from_address: '', from_name: 'My Mate' });
    const [password, setPassword] = useState('');
    const [testTo, setTestTo] = useState('');

    // Seed the form once the stored config loads (password stays blank - write-only).
    useEffect(() => {
        if (mail) setForm({ host: mail.host, port: mail.port, encryption: mail.encryption, username: mail.username, from_address: mail.from_address, from_name: mail.from_name });
    }, [mail]);
    const set = <K extends keyof MailSettingsInput>(k: K, v: MailSettingsInput[K]) => setForm((f) => ({ ...f, [k]: v }));

    function save() {
        update.mutate(
            { ...form, password: password || undefined }, // blank = keep stored secret
            {
                onSuccess: () => {
                    setPassword('');
                    pushToast({ title: 'Mail server saved', detail: 'Used for email alert notifications.', tone: 'info' });
                },
                onError: () => pushToast({ title: 'Couldn\'t save mail server', tone: 'down' }),
            },
        );
    }

    function sendTest() {
        test.mutate(testTo, {
            onSuccess: () => pushToast({ title: 'Test email sent', detail: `Check ${testTo}.`, tone: 'up' }),
            onError: (e: unknown) => {
                const detail = (e as { response?: { data?: { error?: string } } })?.response?.data?.error;
                pushToast({ title: 'Test email failed', detail, tone: 'down' });
            },
        });
    }

    return (
        <section className={card}>
            <div className="mb-4 flex items-center gap-2">
                <Envelope weight="light" className="h-4 w-4 text-white/40" />
                <div>
                    <h2 className="text-sm font-bold text-white">Outgoing mail server (SMTP)</h2>
                    <p className="text-xs text-white/40">Used to deliver email alert notifications. Password is encrypted &amp; never shown.</p>
                </div>
            </div>

            <div className="space-y-2.5">
                <div className="flex gap-2.5">
                    <input className={field} placeholder="SMTP host (e.g. smtp.gmail.com)" value={form.host} onChange={(e) => set('host', e.target.value)} />
                    <input
                        className={`${numField} text-left`}
                        type="number"
                        placeholder="587"
                        value={form.port}
                        onChange={(e) => set('port', Number(e.target.value))}
                    />
                    <select className="w-28 shrink-0 rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60" value={form.encryption} onChange={(e) => set('encryption', e.target.value as MailSettingsInput['encryption'])}>
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <input className={field} placeholder="Username (optional)" value={form.username ?? ''} onChange={(e) => set('username', e.target.value)} />
                <input
                    className={field}
                    type="password"
                    placeholder={mail?.password_set ? 'Password (leave blank to keep)' : 'Password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
                <div className="flex gap-2.5">
                    <input className={field} type="email" placeholder="From address (alerts@yourco.com)" value={form.from_address} onChange={(e) => set('from_address', e.target.value)} />
                    <input className={field} placeholder="From name" value={form.from_name ?? ''} onChange={(e) => set('from_name', e.target.value)} />
                </div>
            </div>

            <div className="mt-4 flex items-center justify-between gap-3">
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <input className={field} type="email" placeholder="Send a test email to..." value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                    <button
                        onClick={sendTest}
                        disabled={test.isPending || !testTo || !mail?.configured}
                        title={mail?.configured ? 'Send a test email' : 'Save the server first'}
                        className="flex shrink-0 items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-2 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white disabled:opacity-40"
                    >
                        <PaperPlaneTilt weight="bold" className="h-3.5 w-3.5" /> {test.isPending ? 'Sending...' : 'Test'}
                    </button>
                </div>
                <button
                    onClick={save}
                    disabled={update.isPending || form.host === '' || form.from_address === ''}
                    className="shrink-0 rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Save server'}
                </button>
            </div>
        </section>
    );
}

/**
 * Config-backup engine (Rusted) connection settings. The API token +
 * default SSH password are write-only (blank = keep). "Test" pings Rusted\'s /healthz. The
 * default SSH login is only used for devices whose own credential can\'t be reused for SSH.
 */
function BackupEngineSection() {
    const { data: cfg } = useBackupSettings();
    const update = useUpdateBackupSettings();
    const test = useTestBackupEngine();
    const [form, setForm] = useState<BackupSettingsInput>({ api_url: 'http://127.0.0.1:8410', timeout: 120, ssh_username: '' });
    const [token, setToken] = useState('');
    const [sshPassword, setSshPassword] = useState('');

    useEffect(() => {
        if (cfg) setForm({ api_url: cfg.api_url, timeout: cfg.timeout, ssh_username: cfg.ssh_username });
    }, [cfg]);
    const set = <K extends keyof BackupSettingsInput>(k: K, v: BackupSettingsInput[K]) => setForm((f) => ({ ...f, [k]: v }));

    function save() {
        update.mutate(
            { ...form, api_token: token || undefined, ssh_password: sshPassword || undefined },
            {
                onSuccess: () => {
                    setToken('');
                    setSshPassword('');
                    pushToast({ title: 'Backup engine saved', detail: 'Used to capture device configs over SSH.', tone: 'info' });
                },
                onError: () => pushToast({ title: 'Couldn\'t save backup engine', tone: 'down' }),
            },
        );
    }

    function runTest() {
        test.mutate(undefined, {
            onSuccess: (r) =>
                pushToast(
                    r.ok
                        ? { title: 'Backup engine reachable', detail: 'Rusted answered on the configured URL.', tone: 'up' }
                        : { title: 'Backup engine unreachable', detail: 'Check the URL/token and that Rusted is running.', tone: 'down' },
                ),
            onError: () => pushToast({ title: 'Backup engine unreachable', tone: 'down' }),
        });
    }

    return (
        <section className={card}>
            <div className="mb-4 flex items-center gap-2">
                <FloppyDisk weight="light" className="h-4 w-4 text-white/40" />
                <div>
                    <h2 className="text-sm font-bold text-white">Config backup engine (Rusted)</h2>
                    <p className="text-xs text-white/40">Captures device configs over SSH &amp; versions them in git. Token &amp; SSH password are encrypted &amp; never shown.</p>
                </div>
            </div>

            <div className="space-y-2.5">
                <div className="flex gap-2.5">
                    <input className={field} placeholder="Rusted API URL (http://127.0.0.1:8410)" value={form.api_url} onChange={(e) => set('api_url', e.target.value)} />
                    <input
                        className={`${numField} text-left`}
                        type="number"
                        placeholder="120"
                        title="Per-call timeout (seconds)"
                        value={form.timeout ?? 120}
                        onChange={(e) => set('timeout', Number(e.target.value))}
                    />
                </div>
                <input
                    className={field}
                    type="password"
                    placeholder={cfg?.api_token_set ? 'API token (leave blank to keep)' : 'API bearer token'}
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                />
                <p className="pt-1 text-[10px] font-medium uppercase tracking-[0.16em] text-white/25">Default SSH login (fallback)</p>
                <input className={field} placeholder="SSH username (optional - used when a device has no reusable login)" value={form.ssh_username ?? ''} onChange={(e) => set('ssh_username', e.target.value)} />
                <input
                    className={field}
                    type="password"
                    placeholder={cfg?.ssh_password_set ? 'SSH password (leave blank to keep)' : 'SSH password'}
                    value={sshPassword}
                    onChange={(e) => setSshPassword(e.target.value)}
                />
            </div>

            <div className="mt-4 flex items-center justify-between gap-3">
                <button
                    onClick={runTest}
                    disabled={test.isPending || !cfg?.configured}
                    title={cfg?.configured ? 'Check Rusted is reachable' : 'Save the URL + token first'}
                    className="flex shrink-0 items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-2 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white disabled:opacity-40"
                >
                    <PaperPlaneTilt weight="bold" className="h-3.5 w-3.5" /> {test.isPending ? 'Testing...' : 'Test connection'}
                </button>
                <button
                    onClick={save}
                    disabled={update.isPending || form.api_url === ''}
                    className="shrink-0 rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Save engine'}
                </button>
            </div>
        </section>
    );
}

function CredentialForm({ initial, onDone }: { initial?: Credential; onDone: () => void }) {
    const save = useSaveCredential();
    const [form, setForm] = useState<CredentialInput>({
        id: initial?.id,
        name: initial?.name ?? '',
        type: initial?.type ?? 'snmp',
        api_port: initial?.api_port ?? 8728,
        // SNMP version + non-secret v3 params pre-populate on edit; passphrases start blank.
        snmp_version: initial?.snmp_version ?? '2c',
        snmp_sec_name: initial?.snmp_sec_name ?? '',
        snmp_sec_level: initial?.snmp_sec_level ?? 'authPriv',
        snmp_auth_protocol: initial?.snmp_auth_protocol ?? 'SHA',
        snmp_priv_protocol: initial?.snmp_priv_protocol ?? 'AES',
    });
    const set = <K extends keyof CredentialInput>(k: K, v: CredentialInput[K]) => setForm((f) => ({ ...f, [k]: v }));
    const keepHint = initial ? 'leave blank to keep' : '';

    function submit() {
        save.mutate(form, {
            onSuccess: () => {
                pushToast({ title: initial ? 'Credential updated' : 'Credential added', tone: 'info' });
                onDone();
            },
            onError: () => pushToast({ title: 'Couldn\'t save credential', tone: 'down' }),
        });
    }

    return (
        <div className="space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <input className={field} placeholder="Name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <select className={field} value={form.type} onChange={(e) => set('type', e.target.value as 'snmp' | 'routeros' | 'ssh')}>
                <option value="snmp">SNMP</option>
                <option value="routeros">RouterOS API</option>
                <option value="ssh">SSH (for backups)</option>
            </select>
            {form.type === 'snmp' ? (
                <>
                    <select className={field} value={form.snmp_version ?? '2c'} onChange={(e) => set('snmp_version', e.target.value as CredentialInput['snmp_version'])}>
                        <option value="2c">SNMP v2c</option>
                        <option value="1">SNMP v1</option>
                        <option value="3">SNMP v3</option>
                    </select>
                    {form.snmp_version === '3' ? (
                        <>
                            <input
                                className={field}
                                placeholder="Security (user) name"
                                value={form.snmp_sec_name ?? ''}
                                onChange={(e) => set('snmp_sec_name', e.target.value)}
                            />
                            <select className={field} value={form.snmp_sec_level ?? 'authPriv'} onChange={(e) => set('snmp_sec_level', e.target.value as CredentialInput['snmp_sec_level'])}>
                                <option value="authPriv">authPriv (auth + encrypt)</option>
                                <option value="authNoPriv">authNoPriv (auth only)</option>
                                <option value="noAuthNoPriv">noAuthNoPriv</option>
                            </select>
                            {form.snmp_sec_level !== 'noAuthNoPriv' && (
                                <div className="grid grid-cols-2 gap-2">
                                    <select className={field} value={form.snmp_auth_protocol ?? 'SHA'} onChange={(e) => set('snmp_auth_protocol', e.target.value)}>
                                        {['SHA', 'MD5', 'SHA-256', 'SHA-512', 'SHA-224', 'SHA-384'].map((p) => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                    <input
                                        className={field}
                                        type="password"
                                        placeholder={`Auth passphrase ${keepHint}`.trim()}
                                        value={form.snmp_auth_passphrase ?? ''}
                                        onChange={(e) => set('snmp_auth_passphrase', e.target.value)}
                                    />
                                </div>
                            )}
                            {form.snmp_sec_level === 'authPriv' && (
                                <div className="grid grid-cols-2 gap-2">
                                    <select className={field} value={form.snmp_priv_protocol ?? 'AES'} onChange={(e) => set('snmp_priv_protocol', e.target.value)}>
                                        {['AES', 'DES', 'AES-192', 'AES-256'].map((p) => <option key={p} value={p}>{p}</option>)}
                                    </select>
                                    <input
                                        className={field}
                                        type="password"
                                        placeholder={`Privacy passphrase ${keepHint}`.trim()}
                                        value={form.snmp_priv_passphrase ?? ''}
                                        onChange={(e) => set('snmp_priv_passphrase', e.target.value)}
                                    />
                                </div>
                            )}
                        </>
                    ) : (
                        <input
                            className={field}
                            type="password"
                            placeholder={`SNMP community ${keepHint}`.trim()}
                            value={form.snmp_community ?? ''}
                            onChange={(e) => set('snmp_community', e.target.value)}
                        />
                    )}
                </>
            ) : (
                <>
                    <input
                        className={field}
                        placeholder={`Username ${keepHint}`.trim()}
                        value={form.username ?? ''}
                        onChange={(e) => set('username', e.target.value)}
                    />
                    <input
                        className={field}
                        type="password"
                        placeholder={
                            form.type === 'ssh'
                                ? `Password ${initial ? 'leave blank to keep' : '(or use a key below)'}`.trim()
                                : `Password ${keepHint}`.trim()
                        }
                        value={form.password ?? ''}
                        onChange={(e) => set('password', e.target.value)}
                    />
                    {form.type === 'ssh' && (
                        <>
                            <textarea
                                className={`${field} min-h-24 resize-y font-mono text-xs`}
                                placeholder={`SSH private key (PEM / OpenSSH) ${initial && initial.has_private_key ? 'leave blank to keep' : 'optional'}`.trim()}
                                value={form.private_key ?? ''}
                                onChange={(e) => set('private_key', e.target.value)}
                                spellCheck={false}
                            />
                            <p className="px-1 text-[11px] text-white/35">
                                Provide a password, a private key, or both. The key is stored encrypted and used for
                                key-based SSH to the device.
                            </p>
                        </>
                    )}
                    {form.type === 'routeros' && (
                        <input
                            className={`${field} tabular-nums`}
                            type="number"
                            placeholder="API port (8728)"
                            value={form.api_port ?? ''}
                            onChange={(e) => set('api_port', e.target.value === '' ? null : Number(e.target.value))}
                        />
                    )}
                </>
            )}
            <div className="flex items-center justify-end gap-2 pt-1">
                <button onClick={onDone} className="rounded-full px-3 py-1.5 text-sm text-white/55 hover:text-white/90">
                    Cancel
                </button>
                <button
                    onClick={submit}
                    disabled={save.isPending || form.name === ''}
                    className="rounded-full bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {save.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

/**
 * A Laravel validation message across the given fields when the server actually rejected
 * the request (a real HTTP response came back) - else `null`, so the caller can tell
 * "the server said no" apart from "no response reached us" (dropped connection, etc.),
 * where the mutation may well have gone through server-side despite the client erroring.
 */
function validationError(e: unknown, fields: string[]): string | null {
    const response = (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response;
    if (!response) return null;
    for (const f of fields) {
        if (response.data?.errors?.[f]?.[0]) return response.data.errors[f][0];
    }
    return 'Couldn\'t change the password.';
}

function AccountSection() {
    const update = useUpdatePassword();
    const [currentPassword, setCurrentPassword] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState<string | null>(null);

    function submit() {
        if (update.isPending) return; // guard a fast double-click while the first request is in flight
        setError(null);
        update.mutate(
            { current_password: currentPassword, password, password_confirmation: confirm },
            {
                onSuccess: () => {
                    setError(null); // clear a stale error left over from an earlier failed attempt
                    setCurrentPassword('');
                    setPassword('');
                    setConfirm('');
                    pushToast({ title: 'Password changed', tone: 'up' });
                },
                onError: (e) => {
                    const message = validationError(e, ['current_password', 'password']);
                    // No response reached us (dropped connection, etc.) - the request may well
                    // have been processed server-side despite that, so don\'t flatly claim failure.
                    setError(message ?? 'Lost the response after sending this - check by logging out and back in with the new password before retrying.');
                },
            },
        );
    }

    return (
        <section className={card}>
            <div className="mb-4 flex items-center gap-2">
                <LockKey weight="light" className="h-4 w-4 text-white/40" />
                <div>
                    <h2 className="text-sm font-bold text-white">Change password</h2>
                    <p className="text-xs text-white/40">Updates your own operator login. At least 10 characters, mixed case &amp; a number.</p>
                </div>
            </div>

            <div className="space-y-2.5">
                <input
                    className={field}
                    type="password"
                    autoComplete="current-password"
                    placeholder="Current password"
                    value={currentPassword}
                    onChange={(e) => setCurrentPassword(e.target.value)}
                />
                <input
                    className={field}
                    type="password"
                    autoComplete="new-password"
                    placeholder="New password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
                <input
                    className={field}
                    type="password"
                    autoComplete="new-password"
                    placeholder="Confirm new password"
                    value={confirm}
                    onChange={(e) => setConfirm(e.target.value)}
                />
                {error && <p className="text-xs text-rose-400/90">{error}</p>}
            </div>

            <div className="mt-4 flex justify-end">
                <button
                    onClick={submit}
                    disabled={update.isPending || !currentPassword || !password || !confirm}
                    className="rounded-full bg-emerald-500 px-5 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {update.isPending ? 'Saving...' : 'Change password'}
                </button>
            </div>
        </section>
    );
}

function CredentialsSection() {
    const { data: creds } = useCredentials();
    const del = useDeleteCredential();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<Credential | null>(null);

    return (
        <section className={card}>
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h2 className="text-sm font-bold text-white">Credentials</h2>
                    <p className="text-xs text-white/40">SNMP communities &amp; RouterOS logins - encrypted, never shown.</p>
                </div>
                {!adding && (
                    <button
                        onClick={() => {
                            setAdding(true);
                            setEditing(null);
                        }}
                        className="flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white"
                    >
                        <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                    </button>
                )}
            </div>

            {adding && <CredentialForm onDone={() => setAdding(false)} />}

            <div className="mt-3 space-y-2">
                {creds?.map((c) =>
                    editing === c.id ? (
                        <CredentialForm key={c.id} initial={c} onDone={() => setEditing(null)} />
                    ) : (
                        <div key={c.id} className="flex items-center gap-3 rounded-xl bg-white/[0.02] px-3 py-2 ring-1 ring-white/[0.06]">
                            <Key weight="light" className="h-4 w-4 shrink-0 text-white/40" />
                            <span className="min-w-0 flex-1 truncate text-sm text-white/85">
                                {c.name}
                                <span className="text-white/35"> - {c.type === 'snmp' ? 'SNMP' : c.type === 'ssh' ? 'SSH' : 'RouterOS'}</span>
                                {c.device_count > 0 && <span className="text-white/30"> - {c.device_count} device(s)</span>}
                            </span>
                            {!c.has_secret && <span className="text-[10px] text-amber-300/80">no secret</span>}
                            <button onClick={() => { setEditing(c.id); setAdding(false); }} title="Edit" className="rounded-md p-1 text-white/40 hover:bg-white/5 hover:text-white/80">
                                <PencilSimple weight="bold" className="h-3.5 w-3.5" />
                            </button>
                            <button
                                onClick={() => setDeleting(c)}
                                title="Delete"
                                className="rounded-md p-1 text-white/40 hover:bg-rose-500/10 hover:text-rose-300"
                            >
                                <Trash weight="bold" className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    ),
                )}
                {creds && creds.length === 0 && !adding && (
                    <p className="text-xs text-white/40">No credentials yet - add one for SNMP or the RouterOS API.</p>
                )}
            </div>

            {deleting && (
                <ConfirmDialog
                    title="Delete credential"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete the credential <span className="font-semibold text-white/85">{deleting.name}</span>?
                            {deleting.device_count > 0 && (
                                <> {deleting.device_count} device(s) will lose it.</>
                            )}
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={() =>
                        del.mutate(deleting.id, {
                            onSuccess: () => setDeleting(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t delete the credential', tone: 'down' });
                                setDeleting(null);
                            },
                        })
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </section>
    );
}

/**
 * A strong random operator password. Uses the crypto RNG (never Math.random)
 * and guarantees one lower / upper / digit / symbol so it always clears the server floor
 * (`Password::min(10)->letters()->mixedCase()->numbers()`). Ambiguous glyphs (0/O/1/l/I)
 * are excluded so an admin can read it out loud without confusion.
 */
function generatePassword(length = 16): string {
    const lower = 'abcdefghijkmnpqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const digits = '23456789';
    const symbols = '!@#$%^&*-_=+';
    const all = lower + upper + digits + symbols;
    const rand = (max: number) => crypto.getRandomValues(new Uint32Array(1))[0] % max;
    const pick = (set: string) => set[rand(set.length)];

    const chars = [pick(lower), pick(upper), pick(digits), pick(symbols)];
    while (chars.length < length) chars.push(pick(all));
    // Fisher-Yates so the guaranteed-category chars aren\'t always in the first four slots.
    for (let i = chars.length - 1; i > 0; i--) {
        const j = rand(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}

function UserForm({ initial, onDone }: { initial?: Operator; onDone: () => void }) {
    const save = useSaveUser();
    const [form, setForm] = useState<UserInput>({
        id: initial?.id,
        name: initial?.name ?? '',
        email: initial?.email ?? '',
        is_admin: initial?.is_admin ?? false,
        restricted: initial?.restricted ?? false,
        map_ids: initial?.map_ids ?? [],
    });
    const { data: allMaps } = useMaps(); // for the per-map access picker (GitHub #28)
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const set = <K extends keyof UserInput>(k: K, v: UserInput[K]) => setForm((f) => ({ ...f, [k]: v }));
    const editing = Boolean(initial);

    function generate() {
        const pw = generatePassword();
        setPassword(pw);
        setShowPassword(true); // reveal so the admin can see/share what was set
        navigator.clipboard
            ?.writeText(pw)
            .then(() => pushToast({ title: 'Password generated & copied', detail: 'Share it with the operator - they can change it later.', tone: 'info' }))
            .catch(() => pushToast({ title: 'Password generated', detail: 'Copy it before saving - it won\'t be shown again.', tone: 'info' }));
    }

    function submit() {
        if (save.isPending) return;
        setError(null);
        save.mutate(
            { ...form, password: password || undefined },
            {
                onSuccess: () => {
                    pushToast({ title: editing ? 'Operator updated' : 'Operator added', tone: 'up' });
                    onDone();
                },
                onError: (e) => setError(validationError(e, ['name', 'email', 'password', 'is_admin']) ?? 'Couldn\'t save the operator.'),
            },
        );
    }

    return (
        <div className="space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <input className={field} placeholder="Name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <input
                className={field}
                type="email"
                autoComplete="off"
                placeholder="Email"
                value={form.email ?? ''}
                onChange={(e) => set('email', e.target.value)}
            />
            <div className="flex items-center gap-2">
                <div className="relative min-w-0 flex-1">
                    <input
                        className={`${field} pr-9`}
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        placeholder={editing ? 'New password (leave blank to keep)' : 'Password - 10+ chars, mixed case & a number'}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                    {password !== '' && (
                        <button
                            type="button"
                            onClick={() => setShowPassword((s) => !s)}
                            title={showPassword ? 'Hide' : 'Show'}
                            className="absolute inset-y-0 right-2 my-auto grid h-6 w-6 place-items-center rounded-md text-white/40 hover:text-white/80"
                        >
                            {showPassword ? <EyeSlash weight="bold" className="h-4 w-4" /> : <Eye weight="bold" className="h-4 w-4" />}
                        </button>
                    )}
                </div>
                <button
                    type="button"
                    onClick={generate}
                    title="Generate a strong random password"
                    className="flex shrink-0 items-center gap-1.5 rounded-xl bg-white/[0.04] px-3 py-2 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white"
                >
                    <ArrowsClockwise weight="bold" className="h-3.5 w-3.5" /> Generate
                </button>
            </div>
            <label className="flex cursor-pointer items-center gap-2.5 px-1 py-1 text-sm text-white/70">
                <input
                    type="checkbox"
                    checked={form.is_admin}
                    onChange={(e) => set('is_admin', e.target.checked)}
                    className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-emerald-500 focus:ring-emerald-400/60"
                />
                <span className="flex items-center gap-1.5">
                    <ShieldCheck weight="bold" className="h-3.5 w-3.5 text-emerald-300/80" /> Administrator - can manage operator accounts
                </span>
            </label>

            {/* Per-map access (GitHub #28). Only meaningful for non-admins; admins see everything. */}
            {!form.is_admin && (
                <>
                    <label className="flex cursor-pointer items-center gap-2.5 px-1 py-1 text-sm text-white/70">
                        <input
                            type="checkbox"
                            checked={form.restricted ?? false}
                            onChange={(e) => set('restricted', e.target.checked)}
                            className="h-4 w-4 rounded border-white/20 bg-white/[0.05] text-emerald-500 focus:ring-emerald-400/60"
                        />
                        <span>Restrict to specific maps - only sees the maps and devices below</span>
                    </label>
                    {form.restricted && (
                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg bg-black/20 p-2 ring-1 ring-white/10">
                            {(allMaps ?? []).length === 0 && <p className="px-1 text-xs text-white/35">No maps yet.</p>}
                            {(allMaps ?? []).map((m) => {
                                const on = (form.map_ids ?? []).includes(m.id);
                                return (
                                    <label key={m.id} className="flex cursor-pointer items-center gap-2 px-1 py-0.5 text-xs text-white/70">
                                        <input
                                            type="checkbox"
                                            checked={on}
                                            onChange={(e) =>
                                                set('map_ids', e.target.checked
                                                    ? [...(form.map_ids ?? []), m.id]
                                                    : (form.map_ids ?? []).filter((id) => id !== m.id))
                                            }
                                            className="h-3.5 w-3.5 rounded border-white/20 bg-white/[0.05] text-emerald-500 focus:ring-emerald-400/60"
                                        />
                                        {m.parent_map_id ? '- ' : ''}{m.name}
                                    </label>
                                );
                            })}
                            <p className="px-1 pt-1 text-[10px] text-white/35">Granting a parent map includes its sub-maps.</p>
                        </div>
                    )}
                </>
            )}
            {error && <p className="text-xs text-rose-400/90">{error}</p>}
            <div className="flex items-center justify-end gap-2 pt-1">
                <button onClick={onDone} className="rounded-full px-3 py-1.5 text-sm text-white/55 hover:text-white/90">
                    Cancel
                </button>
                <button
                    onClick={submit}
                    disabled={save.isPending || form.name === '' || (form.email ?? '') === '' || (!editing && password === '')}
                    className="rounded-full bg-emerald-500 px-4 py-1.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    {save.isPending ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}

/**
 * Operator management. Any operator sees the roster; only an admin gets the
 * add/edit/delete controls (and the API enforces it - the buttons are just not shown to
 * non-admins, who also receive a reduced payload with no emails).
 */
function UsersSection() {
    const { data: me } = useCurrentUser();
    const { data: users } = useUsers();
    const del = useDeleteUser();
    const isAdmin = me?.is_admin ?? false;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<Operator | null>(null);

    return (
        <section className={card}>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <UsersThree weight="light" className="h-4 w-4 text-white/40" />
                    <div>
                        <h2 className="text-sm font-bold text-white">Operators</h2>
                        <p className="text-xs text-white/40">
                            {isAdmin ? 'Add, edit or remove operator logins. Passwords are never shown.' : 'Only an admin can add or change operators.'}
                        </p>
                    </div>
                </div>
                {isAdmin && !adding && (
                    <button
                        onClick={() => {
                            setAdding(true);
                            setEditing(null);
                        }}
                        className="flex items-center gap-1.5 rounded-full bg-white/[0.04] px-3 py-1.5 text-xs font-medium text-white/70 ring-1 ring-white/10 transition hover:text-white"
                    >
                        <Plus weight="bold" className="h-3.5 w-3.5" /> Add
                    </button>
                )}
            </div>

            {isAdmin && adding && <UserForm onDone={() => setAdding(false)} />}

            <div className="mt-3 space-y-2">
                {users?.map((u) =>
                    isAdmin && editing === u.id ? (
                        <UserForm key={u.id} initial={u} onDone={() => setEditing(null)} />
                    ) : (
                        <div key={u.id} className="flex items-center gap-3 rounded-xl bg-white/[0.02] px-3 py-2 ring-1 ring-white/[0.06]">
                            <span
                                className={`grid h-6 w-6 shrink-0 place-items-center rounded-lg text-[10px] font-bold ${u.is_admin ? 'bg-emerald-500/15 text-emerald-300' : 'bg-white/[0.05] text-white/50'}`}
                            >
                                {u.name.trim().charAt(0).toUpperCase() || '?'}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-sm text-white/85">
                                {u.name}
                                {u.email && <span className="text-white/35"> - {u.email}</span>}
                            </span>
                            {u.is_admin && (
                                <span className="flex shrink-0 items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-300 ring-1 ring-emerald-400/20">
                                    <ShieldCheck weight="bold" className="h-3 w-3" /> Admin
                                </span>
                            )}
                            {isAdmin && (
                                <>
                                    <button
                                        onClick={() => {
                                            setEditing(u.id);
                                            setAdding(false);
                                        }}
                                        title="Edit"
                                        className="rounded-md p-1 text-white/40 hover:bg-white/5 hover:text-white/80"
                                    >
                                        <PencilSimple weight="bold" className="h-3.5 w-3.5" />
                                    </button>
                                    {u.id !== me?.id && (
                                        <button
                                            onClick={() => setDeleting(u)}
                                            title="Delete"
                                            className="rounded-md p-1 text-white/40 hover:bg-rose-500/10 hover:text-rose-300"
                                        >
                                            <Trash weight="bold" className="h-3.5 w-3.5" />
                                        </button>
                                    )}
                                </>
                            )}
                        </div>
                    ),
                )}
            </div>

            {deleting && (
                <ConfirmDialog
                    title="Delete operator"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Delete the operator <span className="font-semibold text-white/85">{deleting.name}</span>? They will no longer be able to sign in.
                        </>
                    }
                    confirmLabel="Delete"
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={() =>
                        del.mutate(deleting.id, {
                            onSuccess: () => setDeleting(null),
                            onError: (e) => {
                                pushToast({ title: validationError(e, ['user']) ?? 'Couldn\'t delete the operator', tone: 'down' });
                                setDeleting(null);
                            },
                        })
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </section>
    );
}

const AGENT_STATUS: Record<string, { label: string; dot: string; text: string }> = {
    online: { label: 'Online', dot: 'bg-emerald-400', text: 'text-emerald-300' },
    offline: { label: 'Offline', dot: 'bg-white/30', text: 'text-white/40' },
    enrolled: { label: 'Never connected', dot: 'bg-amber-400', text: 'text-amber-300/90' },
};

/**
 * Remote agents (probes). Enrol an agent -> the token is shown ONCE with the config an
 * operator pastes on the probe host. Admin-only for enrol/delete; any operator sees the
 * roster + live online/offline status.
 */
function AgentsSection() {
    const { data: agents } = useAgents();
    const enrol = useEnrolAgent();
    const del = useDeleteAgent();
    const isAdmin = useIsAdmin();
    const [name, setName] = useState('');
    const [token, setToken] = useState<{ name: string; token: string } | null>(null);
    const [deleting, setDeleting] = useState<{ id: number; name: string; device_count: number } | null>(null);

    function submit() {
        if (enrol.isPending || name.trim() === '') return;
        enrol.mutate(name.trim(), {
            onSuccess: (r) => {
                setToken({ name: name.trim(), token: r.token });
                setName('');
            },
            onError: () => pushToast({ title: 'Couldn\'t enrol the agent', tone: 'down' }),
        });
    }

    return (
        <section className={card}>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Broadcast weight="light" className="h-4 w-4 text-white/40" />
                    <div>
                        <h2 className="text-sm font-bold text-white">Remote agents</h2>
                        <p className="text-xs text-white/40">Probes that poll a remote network over an outbound tunnel. Assign devices to an agent on the device form.</p>
                    </div>
                </div>
            </div>

            {/* Token shown once, right after enrolment */}
            {token && (
                <div className="mb-3 rounded-xl bg-emerald-500/[0.08] p-3 ring-1 ring-emerald-400/20">
                    <p className="text-xs font-semibold text-white/80">Agent "{token.name}" enrolled - copy its token now (shown once):</p>
                    <div className="mt-2 flex items-center gap-2">
                        <code className="min-w-0 flex-1 truncate rounded-lg bg-black/40 px-2 py-1.5 font-mono text-[11px] text-emerald-200/90">{token.token}</code>
                        <button
                            onClick={() => {
                                navigator.clipboard?.writeText(token.token);
                                pushToast({ title: 'Token copied', tone: 'info' });
                            }}
                            className="flex shrink-0 items-center gap-1 rounded-lg bg-white/[0.06] px-2 py-1.5 text-xs text-white/70 ring-1 ring-white/10 hover:text-white"
                        >
                            <Copy weight="bold" className="h-3.5 w-3.5" /> Copy
                        </button>
                    </div>
                    <p className="mt-2 text-[11px] leading-relaxed text-white/45">
                        On the probe host: <code className="text-white/60">MYMATE_URL=https://&lt;this-server&gt;</code> and <code className="text-white/60">MYMATE_AGENT_TOKEN=...</code>, then run the agent. See the agent README.
                    </p>
                    <button onClick={() => setToken(null)} className="mt-2 text-xs font-semibold text-emerald-300/90 hover:underline">Done</button>
                </div>
            )}

            {isAdmin && !token && (
                <div className="mb-3 flex items-center gap-2">
                    <input
                        className={field}
                        placeholder="Agent name (e.g. Sydney site)"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && submit()}
                    />
                    <button
                        onClick={submit}
                        disabled={enrol.isPending || name.trim() === ''}
                        className="flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-500 px-4 py-2 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        <Plus weight="bold" className="h-3.5 w-3.5" /> {enrol.isPending ? 'Enrolling...' : 'Enrol'}
                    </button>
                </div>
            )}

            <div className="space-y-2">
                {agents?.map((a) => {
                    const st = AGENT_STATUS[a.status] ?? AGENT_STATUS.offline;
                    return (
                        <div key={a.id} className="flex items-center gap-3 rounded-xl bg-white/[0.02] px-3 py-2 ring-1 ring-white/[0.06]">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${st.dot}`} />
                            <span className="min-w-0 flex-1 truncate text-sm text-white/85">
                                {a.name}
                                <span className={`text-xs ${st.text}`}> - {st.label}</span>
                                {a.platform && <span className="text-white/30"> - {a.platform}</span>}
                                {a.device_count > 0 && <span className="text-white/30"> - {a.device_count} device(s)</span>}
                            </span>
                            {isAdmin && (
                                <button
                                    onClick={() => setDeleting({ id: a.id, name: a.name, device_count: a.device_count })}
                                    title="Remove agent"
                                    className="rounded-md p-1 text-white/40 hover:bg-rose-500/10 hover:text-rose-300"
                                >
                                    <Trash weight="bold" className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>
                    );
                })}
                {agents && agents.length === 0 && (
                    <p className="text-xs text-white/40">No agents yet{isAdmin ? ' - enrol one above to monitor a remote network.' : '.'}</p>
                )}
            </div>

            {deleting && (
                <ConfirmDialog
                    title="Remove agent"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Remove the agent <span className="font-semibold text-white/85">{deleting.name}</span>?
                            {deleting.device_count > 0 && <> Its {deleting.device_count} device(s) will revert to central polling.</>}
                        </>
                    }
                    confirmLabel="Remove"
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={() =>
                        del.mutate(deleting.id, {
                            onSuccess: () => setDeleting(null),
                            onError: () => {
                                pushToast({ title: 'Couldn\'t remove the agent', tone: 'down' });
                                setDeleting(null);
                            },
                        })
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </section>
    );
}

/** Version + whether a newer release is available. */
const STATUS_DOT: Record<StatusLevel, string> = {
    ok: 'bg-emerald-400',
    warn: 'bg-amber-400',
    down: 'bg-rose-400',
};

function SystemStatusSection() {
    const { data, isLoading, refetch, isFetching } = useSystemStatus();

    return (
        <div className="min-w-0 space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <ShieldCheck weight="light" className="h-4 w-4 text-white/50" />
                    <h2 className="text-sm font-bold text-white">System status</h2>
                </div>
                <button
                    onClick={() => refetch()}
                    disabled={isFetching}
                    className="rounded-md p-1 text-white/40 transition hover:bg-white/5 hover:text-white/70 disabled:opacity-40"
                    title="Refresh"
                >
                    <ArrowsClockwise weight="bold" className={`h-3.5 w-3.5 ${isFetching ? 'animate-spin' : ''}`} />
                </button>
            </div>
            {isLoading || !data ? (
                <p className="text-xs text-white/40">Checking...</p>
            ) : (
                <ul className="space-y-1.5">
                    {data.map((c) => (
                        <li key={c.key} className="flex items-start justify-between gap-3 text-xs">
                            <span className="flex shrink-0 items-center gap-2 text-white/70">
                                <span className={`h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[c.status]}`} />
                                {c.label}
                            </span>
                            <span className="text-right text-white/40 [overflow-wrap:anywhere]">{c.detail}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const UPGRADE_STEPS: { label: string; cmd: string }[] = [
    { label: 'Debian / Ubuntu package', cmd: 'sudo apt install ./mymate_<version>_amd64.deb' },
    { label: 'Docker Compose', cmd: 'docker compose pull && docker compose up -d' },
    { label: 'Proxmox LXC', cmd: 'Update the package inside the container (see the .deb step), or import the new template.' },
];

function AboutSection() {
    const { data: u, isLoading } = useUpdateCheck();
    const [showUpgrade, setShowUpgrade] = useState(false);

    return (
        <div className="min-w-0 space-y-2.5 rounded-xl bg-white/[0.03] p-3 ring-1 ring-white/10">
            <div className="flex items-center gap-2">
                <Info weight="light" className="h-4 w-4 text-white/50" />
                <h2 className="text-sm font-bold text-white">About</h2>
            </div>
            {isLoading || !u ? (
                <p className="text-xs text-white/40">Checking for updates...</p>
            ) : (
                <div className="space-y-2 text-xs">
                    <div className="flex items-center justify-between">
                        <span className="text-white/45">Version</span>
                        <span className="font-mono text-white/85">{u.current === 'dev' ? 'dev build' : `v${u.current}`}</span>
                    </div>
                    {u.update_available ? (
                        <div className="space-y-2 rounded-lg bg-amber-500/10 p-3 ring-1 ring-amber-400/25">
                            <div className="flex items-center gap-1.5 font-semibold text-amber-300">
                                <CloudArrowDown weight="bold" className="h-3.5 w-3.5" />
                                Update available - {u.latest}
                            </div>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <a
                                    href={u.url ?? '#'}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 text-amber-200/90 underline-offset-2 hover:underline"
                                >
                                    Release notes <ArrowRight weight="bold" className="h-3 w-3" />
                                </a>
                                <button
                                    onClick={() => setShowUpgrade((s) => !s)}
                                    className="text-amber-200/70 underline-offset-2 hover:underline"
                                >
                                    {showUpgrade ? 'Hide upgrade steps' : 'How to upgrade'}
                                </button>
                            </div>
                            {showUpgrade && (
                                <div className="space-y-2 border-t border-amber-400/15 pt-2">
                                    {UPGRADE_STEPS.map((s) => (
                                        <div key={s.label}>
                                            <div className="text-[11px] font-medium text-amber-200/70">{s.label}</div>
                                            <code className="mt-0.5 block rounded bg-black/30 px-2 py-1 font-mono text-[11px] text-amber-100/90 [overflow-wrap:anywhere]">
                                                {s.cmd}
                                            </code>
                                        </div>
                                    ))}
                                    <p className="text-[11px] text-amber-200/50">
                                        Grab the new package from the release page, then run the step for how you
                                        installed. Your data and config are kept across upgrades.
                                    </p>
                                </div>
                            )}
                        </div>
                    ) : u.latest ? (
                        <p className="flex items-center gap-1.5 text-emerald-300/80">
                            <Check weight="bold" className="h-3.5 w-3.5" /> You're on the latest release.
                        </p>
                    ) : (
                        <p className="text-white/35">Couldn't reach GitHub to check for updates.</p>
                    )}
                </div>
            )}
        </div>
    );
}

function DangerZoneSection() {
    const reset = useFactoryReset();
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [confirmText, setConfirmText] = useState('');
    const ready = password.length > 0 && confirmText.trim().toUpperCase() === 'RESET' && !reset.isPending;

    function run() {
        reset.mutate(password, {
            onSuccess: (r) => {
                pushToast({ title: 'Factory reset complete', detail: r.message, tone: 'info' });
                setOpen(false);
                setPassword('');
                setConfirmText('');
            },
            onError: (e: unknown) => {
                const msg = (e as { response?: { data?: { errors?: { password?: string[] }; message?: string } } })?.response?.data;
                pushToast({ title: 'Reset failed', detail: msg?.errors?.password?.[0] ?? msg?.message ?? 'Check your password.', tone: 'down' });
            },
        });
    }

    return (
        <section className={`${card} ring-rose-500/20`}>
            <div className="mb-3 flex items-center gap-2">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-rose-500/15 text-rose-300 ring-1 ring-rose-400/25">
                    <Trash weight="light" className="h-4 w-4" />
                </span>
                <div>
                    <h2 className="text-sm font-bold text-white">Danger zone</h2>
                    <p className="text-[11px] text-white/40">Irreversible actions</p>
                </div>
            </div>

            <p className="text-xs leading-relaxed text-white/55">
                Factory reset permanently deletes every device, interface, link, map, credential,
                agent, sensor, alert rule and all history - returning My Mate to a clean install.
                Only admin accounts are kept; operator accounts are removed.
            </p>

            {!open ? (
                <button
                    onClick={() => setOpen(true)}
                    className="mt-3 rounded-xl bg-rose-500/10 px-3 py-2 text-xs font-semibold text-rose-300 ring-1 ring-rose-400/25 transition hover:bg-rose-500/20"
                >
                    Factory reset...
                </button>
            ) : (
                <div className="mt-3 space-y-2.5 rounded-xl bg-rose-500/[0.04] p-3 ring-1 ring-rose-400/15">
                    <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Confirm your password"
                        className={field}
                    />
                    <input
                        value={confirmText}
                        onChange={(e) => setConfirmText(e.target.value)}
                        placeholder='Type RESET to confirm'
                        className={field}
                    />
                    <div className="flex items-center justify-end gap-2">
                        <button
                            onClick={() => { setOpen(false); setPassword(''); setConfirmText(''); }}
                            className="rounded-full px-3 py-1.5 text-xs font-medium text-white/60 transition hover:text-white/90"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={run}
                            disabled={!ready}
                            className="rounded-full bg-rose-500 px-4 py-1.5 text-xs font-semibold text-white shadow-[0_8px_24px_-8px_rgba(244,63,94,0.6)] transition hover:bg-rose-400 disabled:opacity-40"
                        >
                            {reset.isPending ? 'Resetting...' : 'Wipe everything'}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}

export function SettingsView() {
    // Engine cadence, mail server and credentials are admin-only config.
    // A read-only operator keeps just self-service (change own password) and the roster.
    const isAdmin = useIsAdmin();

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise mx-auto max-w-6xl space-y-6">
                <header className="flex items-center gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <GearSix weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-base font-bold tracking-tight text-white">Settings</h1>
                        <p className="text-xs text-white/40">
                            {isAdmin ? 'Engine cadence, retention & credentials' : 'Your account'}
                        </p>
                    </div>
                </header>

                {/* Two columns on wide screens so the sections fill the width instead of
                    stacking into a long scroll; single column below lg. items-start keeps each
                    card at its natural height rather than stretching to the tallest in the row. */}
                <div className="grid min-w-0 grid-cols-1 items-start gap-6 lg:grid-cols-2">
                    {isAdmin && <EngineSettings />}
                    {isAdmin && <MailServerSection />}
                    {isAdmin && <BackupEngineSection />}
                    <AccountSection />
                    <AboutSection />
                    {isAdmin && <SystemStatusSection />}
                    <UsersSection />
                    <AgentsSection />
                    {isAdmin && (
                        <div className="min-w-0 lg:col-span-2">
                            <CredentialsSection />
                        </div>
                    )}
                    {isAdmin && (
                        <div className="min-w-0 lg:col-span-2">
                            <SensorsSection />
                        </div>
                    )}
                    {isAdmin && (
                        <div className="min-w-0 lg:col-span-2">
                            <DangerZoneSection />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
