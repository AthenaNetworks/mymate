import { useState, type FormEvent } from 'react';
import { SignIn } from '@phosphor-icons/react';
import { useLogin } from '../api/auth';
import { Logomark } from '../../../components/Logomark';

const field =
    'w-full rounded-xl bg-white/[0.03] px-3.5 py-2.5 text-sm text-white ring-1 ring-white/10 outline-none ' +
    'transition duration-300 ease-fluid placeholder:text-white/30 focus:bg-white/[0.05] focus:ring-2 focus:ring-emerald-400/60';

/** Full-screen sign-in gate. Shown until an operator authenticates. */
export function LoginScreen() {
    const login = useLogin();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    const ready = email.trim() !== '' && password !== '' && !login.isPending;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!ready) return;
        login.mutate({ email: email.trim(), password });
    }

    return (
        <div className="grid min-h-screen place-items-center p-6">
            <form
                onSubmit={submit}
                className="animate-rise w-full max-w-sm rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10"
            >
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-7 ring-1 ring-white/10">
                    <div className="mb-6 flex items-center gap-3">
                        <Logomark size={44} className="rounded-2xl shadow-[0_8px_22px_-8px_rgba(16,185,129,0.7)]" />
                        <div>
                            <h1 className="text-lg font-bold tracking-tight text-white">My Mate</h1>
                            <p className="text-[11px] uppercase tracking-[0.2em] text-white/35">Network Mate - sign in</p>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <label className="block space-y-1.5">
                            <span className="px-1 text-[11px] font-medium text-white/55">Email</span>
                            <input
                                type="email"
                                autoFocus
                                autoComplete="username"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                className={field}
                                placeholder="operator@example.com"
                            />
                        </label>
                        <label className="block space-y-1.5">
                            <span className="px-1 text-[11px] font-medium text-white/55">Password</span>
                            <input
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                className={field}
                                placeholder="••••••••••••"
                            />
                        </label>
                    </div>

                    {login.isError && (
                        <p className="mt-3 text-xs text-rose-400/90">Sign-in failed - check your email and password.</p>
                    )}

                    <button
                        type="submit"
                        disabled={!ready}
                        className="group mt-6 flex w-full items-center justify-center gap-2 rounded-full bg-emerald-500 py-2.5 text-sm font-semibold text-emerald-950 shadow-[0_8px_24px_-8px_rgba(16,185,129,0.6)] transition-all duration-500 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40"
                    >
                        <span>{login.isPending ? 'Signing in...' : 'Sign in'}</span>
                        <SignIn weight="bold" className="h-4 w-4 transition-transform duration-500 ease-fluid group-hover:translate-x-0.5" />
                    </button>
                </div>
            </form>
        </div>
    );
}
