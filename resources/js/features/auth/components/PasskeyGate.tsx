import { useState } from 'react';
import { Fingerprint, SignOut, Warning } from '@phosphor-icons/react';
import { useRegisterPasskey, useVerifyPasskey } from '../api/passkeys';
import { useLogout } from '../api/auth';
import { passkeysSupported } from '../lib/passkey';
import type { PasskeyStage } from '../../../types';

/**
 * The post-password gate. When passkeys are required (or the operator has one), a password login
 * lands here first: 'enrol' forces setting one up, 'challenge' is the 2FA tap. On success the user
 * query is refetched, the stage flips to verified, and the app renders. There's always an escape
 * hatch to sign out (eg on a device that can't do WebAuthn - an admin can mark it exempt instead).
 */
export function PasskeyGate({ stage }: { stage: PasskeyStage }) {
    const register = useRegisterPasskey();
    const verify = useVerifyPasskey();
    const logout = useLogout();
    const [name, setName] = useState('This device');
    const [error, setError] = useState<string | null>(null);

    const enrol = stage === 'enrol';
    const busy = register.isPending || verify.isPending;
    const supported = passkeysSupported();

    async function go() {
        setError(null);
        try {
            if (enrol) await register.mutateAsync(name.trim() || 'Passkey');
            else await verify.mutateAsync();
        } catch (e) {
            const msg = (e as { response?: { data?: { message?: string } }; message?: string });
            setError(msg?.response?.data?.message ?? msg?.message ?? 'That didn\'t work. Please try again.');
        }
    }

    return (
        <div className="grid min-h-dvh place-items-center bg-surface-deep p-6">
            <div className="w-full max-w-sm rounded-2xl bg-surface p-7 ring-1 ring-white/10">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                    <Fingerprint weight="light" className="h-6 w-6" />
                </span>
                <h1 className="mt-4 text-lg font-bold tracking-tight text-white">
                    {enrol ? 'Set up a passkey' : 'Confirm it\'s you'}
                </h1>
                <p className="mt-1.5 text-sm leading-relaxed text-white/55">
                    {enrol
                        ? 'Your administrator requires a passkey. Register one now - a fingerprint, face, or security key - to finish signing in.'
                        : 'Use your passkey to complete sign-in.'}
                </p>

                {!supported && (
                    <div className="mt-4 flex items-start gap-2 rounded-xl bg-amber-500/10 p-3 text-xs text-amber-200 ring-1 ring-amber-400/25">
                        <Warning weight="bold" className="mt-0.5 h-4 w-4 shrink-0" />
                        This browser can't do passkeys (they need HTTPS and a recent browser). Sign in on a supported
                        device, or ask an admin to exempt this account.
                    </div>
                )}

                {enrol && supported && (
                    <input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        maxLength={60}
                        placeholder="Name this passkey"
                        className="mt-4 w-full rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                    />
                )}

                {error && <p className="mt-3 text-xs text-rose-400/90">{error}</p>}

                <button
                    onClick={go}
                    disabled={busy || !supported}
                    className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-emerald-500 px-5 py-2.5 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                >
                    <Fingerprint weight="bold" className="h-4 w-4" />
                    {busy ? 'Waiting for your device...' : enrol ? 'Create passkey' : 'Use passkey'}
                </button>

                <button
                    onClick={() => logout.mutate()}
                    className="mt-3 flex w-full items-center justify-center gap-1.5 text-xs font-medium text-white/45 transition hover:text-white/70"
                >
                    <SignOut weight="bold" className="h-3.5 w-3.5" /> Sign out
                </button>
            </div>
        </div>
    );
}
