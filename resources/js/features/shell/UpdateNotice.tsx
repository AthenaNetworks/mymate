import { useState } from 'react';
import { CloudArrowDown, ArrowRight, X } from '@phosphor-icons/react';
import { useUpdateCheck } from '../settings/api/updateCheck';

// Per-version opt-out: dismissing with "don't show again" stores the version, so a *newer*
// release still pops up later.
const KEY = 'mymate:update-dismissed';

/**
 * A one-time "an update is available" notice shown after login. Closes for the session on
 * dismiss; with "don't show this again" ticked it won't return until a newer version ships.
 * Purely informational (the Settings > About card has the same, plus upgrade steps).
 */
export function UpdateNotice() {
    const { data: u } = useUpdateCheck();
    const [dismissed, setDismissed] = useState(false);
    const [dontShow, setDontShow] = useState(false);

    if (!u?.update_available || !u.latest || dismissed) return null;
    if (localStorage.getItem(KEY) === u.latest) return null; // opted out of this version

    function close() {
        if (dontShow && u?.latest) localStorage.setItem(KEY, u.latest);
        setDismissed(true);
    }

    return (
        <div className="fixed inset-0 z-[70] grid place-items-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={close} />
            <div className="animate-rise relative w-full max-w-sm rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="rounded-[calc(1.5rem-0.25rem)] bg-surface p-6 ring-1 ring-white/10">
                    <button onClick={close} className="absolute right-3 top-3 rounded-lg p-1 text-white/40 transition hover:bg-white/5 hover:text-white/80">
                        <X weight="bold" className="h-4 w-4" />
                    </button>

                    <span className="grid h-10 w-10 place-items-center rounded-xl bg-amber-500/15 text-amber-300 ring-1 ring-amber-400/25">
                        <CloudArrowDown weight="duotone" className="h-5 w-5" />
                    </span>
                    <h2 className="mt-3 text-base font-bold tracking-tight text-white">Update available</h2>
                    <p className="mt-1 text-sm text-white/55">
                        My Mate <span className="font-mono text-white/85">{u.latest}</span> is out - you're on{' '}
                        <span className="font-mono text-white/70">{u.current === 'dev' ? 'a dev build' : `v${u.current}`}</span>.
                    </p>

                    {u.url && (
                        <a
                            href={u.url}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-3 inline-flex items-center gap-1.5 text-sm text-amber-300/90 underline-offset-2 hover:underline"
                        >
                            What's new & how to upgrade <ArrowRight weight="bold" className="h-3.5 w-3.5" />
                        </a>
                    )}

                    <div className="mt-5 flex items-center justify-between gap-3">
                        <label className="flex cursor-pointer items-center gap-2 text-xs text-white/55">
                            <input type="checkbox" checked={dontShow} onChange={(e) => setDontShow(e.target.checked)} className="h-3.5 w-3.5 accent-emerald-500" />
                            Don't show this again
                        </label>
                        <button
                            onClick={close}
                            className="rounded-full bg-white/[0.06] px-4 py-1.5 text-sm font-medium text-white/80 ring-1 ring-white/10 transition hover:bg-white/10 hover:text-white"
                        >
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
