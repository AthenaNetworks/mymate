import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CaretRight, GearSix, SpeakerHigh, SpeakerSlash } from '@phosphor-icons/react';
import { isSoundMuted, playSound, setSoundPrefs, SOUND_DEFAULTS, useAudioUnlocked, useSoundPrefs, type SoundContext } from '../lib/alertSound';
import { Toggle } from './Toggle';

const SNOOZES = [
    { mins: 15, label: '15 min' },
    { mins: 60, label: '1 hour' },
    { mins: 240, label: '4 hours' },
];

/** Re-render every `ms` while `on`, so a snooze counts down and ends by itself. */
function useTick(on: boolean, ms: number) {
    const [, setN] = useState(0);
    useEffect(() => {
        if (!on) return;
        const t = setInterval(() => setN((n) => n + 1), ms);
        return () => clearInterval(t);
    }, [on, ms]);
}

const hhmm = (t: number) => new Date(t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

/**
 * The speaker button: turns alert sounds on or off and mutes them for a while. Everything else
 * lives in Settings > Sounds, which `onOpenSettings` opens. On a shared wallboard link (`shared`)
 * nobody is signed in, so there's no settings link and it starts off.
 *
 * Green = on, rose = muted (with the minutes left under it while snoozed), and an amber dot
 * while the browser is still waiting for the click it needs before it will play anything.
 */
export function SoundButton({
    context = 'app',
    onOpenSettings,
    className = '',
}: {
    context?: SoundContext;
    onOpenSettings?: () => void;
    className?: string;
}) {
    const p = useSoundPrefs(context);
    const unlocked = useAudioUnlocked();
    const [open, setOpen] = useState(false);
    const wrap = useRef<HTMLDivElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    // The popover is portalled and fixed, so a scrolling top bar (phones) can't clip it. It sits
    // under the button, right-aligned to it, kept on screen.
    const [pos, setPos] = useState<{ top: number; right: number } | null>(null);
    useLayoutEffect(() => {
        if (!open) return;
        const place = () => {
            const r = wrap.current?.getBoundingClientRect();
            if (!r) return;
            const width = Math.min(288, window.innerWidth - 24);
            const right = Math.min(Math.max(12, window.innerWidth - r.right), window.innerWidth - width - 12);
            setPos({ top: r.bottom + 10, right });
        };
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true); // the top bar sliding sideways
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open]);
    const shared = context === 'shared';

    const now = Date.now();
    const snoozed = p.enabled && p.mutedUntil > now;
    const muted = isSoundMuted(p, now);
    useTick(snoozed, 15_000);
    // A snooze that has run out clears itself, so the button and the popover agree.
    useEffect(() => {
        if (p.mutedUntil !== 0 && p.mutedUntil <= now) setSoundPrefs(context, { mutedUntil: 0 });
    });
    const minsLeft = snoozed ? Math.ceil((p.mutedUntil - now) / 60_000) : 0;
    const left = minsLeft >= 60 ? `${Math.floor(minsLeft / 60)}h${minsLeft % 60 ? minsLeft % 60 : ''}` : `${minsLeft}m`;

    useEffect(() => {
        if (!open) return;
        const onDown = (e: PointerEvent) => {
            const t = e.target as Node;
            if (!wrap.current?.contains(t) && !panel.current?.contains(t)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.stopPropagation(); // don't also leave wallboard mode
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onDown);
        window.addEventListener('keydown', onKey, true);
        return () => {
            document.removeEventListener('pointerdown', onDown);
            window.removeEventListener('keydown', onKey, true);
        };
    }, [open]);

    const title = !p.enabled
        ? shared
            ? 'Sound is off on this wallboard'
            : 'Alert sounds off'
        : snoozed
          ? `Alert sounds muted until ${hhmm(p.mutedUntil)}`
          : !unlocked
            ? 'Click anywhere to allow alert sounds (the browser needs a click first)'
            : 'Alert sounds on';

    const status = !p.enabled
        ? shared
            ? 'Off on this wallboard until you turn it on'
            : 'Off until you turn it back on'
        : snoozed
          ? `Muted until ${hhmm(p.mutedUntil)}`
          : !unlocked
            ? null
            : shared || p.source === 'devices'
              ? 'For any device down or back up'
              : p.alertTypes.length === 0
                ? 'No alert types ticked in Settings'
                : `For ${p.alertTypes.length} alert type${p.alertTypes.length === 1 ? '' : 's'}`;

    const setOn = (on: boolean) => {
        setSoundPrefs(context, { enabled: on, mutedUntil: 0 });
        // Turning it on is a click, so this plays: a quick check that sound comes out.
        if (on) playSound(shared ? SOUND_DEFAULTS.firingSound : p.firingSound, shared ? SOUND_DEFAULTS.volume : p.volume);
    };

    return (
        <div ref={wrap} className="relative">
            <button
                onClick={() => setOpen((o) => !o)}
                title={title}
                aria-label={title}
                aria-haspopup="dialog"
                aria-expanded={open}
                className={`relative rounded-lg p-1.5 transition-colors duration-300 ease-fluid hover:bg-white/5 ${
                    muted ? 'text-rose-400/80 hover:text-rose-300' : 'text-emerald-400/90 hover:text-emerald-300'
                } ${className}`}
            >
                {muted ? <SpeakerSlash weight="bold" className="h-4 w-4" /> : <SpeakerHigh weight="bold" className="h-4 w-4" />}
                {!muted && !unlocked && <span className="absolute right-0.5 top-0.5 h-1.5 w-1.5 rounded-full bg-amber-400 ring-2 ring-surface" />}
                {snoozed && (
                    <span className="absolute -bottom-2 left-1/2 -translate-x-1/2 font-mono text-[9px] leading-none text-rose-300">{left}</span>
                )}
            </button>

            {open &&
                pos &&
                createPortal(
                    <div
                        ref={panel}
                        role="dialog"
                        aria-label="Alert sounds"
                        style={{ top: pos.top, right: pos.right }}
                        className="fixed z-[70] w-72 max-w-[calc(100vw-1.5rem)] rounded-2xl bg-surface/95 p-1.5 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10 backdrop-blur-2xl"
                    >
                        <div className="flex items-center gap-3 p-2.5">
                            <div className="min-w-0 flex-1">
                                <div className="text-[13px] font-semibold text-white">Alert sounds</div>
                                <div className="mt-0.5 text-[11px] text-white/40">
                                    {status ?? <span className="text-amber-300">Click anywhere on the page to allow sound</span>}
                                </div>
                            </div>
                            <Toggle checked={p.enabled && !snoozed} onChange={setOn} label="Alert sounds" />
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5 px-2.5 pb-2.5">
                            <span className="text-[11px] text-white/35">Mute for</span>
                            {SNOOZES.map((s) => (
                                <button
                                    key={s.mins}
                                    onClick={() => setSoundPrefs(context, { enabled: true, mutedUntil: Date.now() + s.mins * 60_000 })}
                                    className="rounded-full px-2 py-0.5 text-[11px] text-white/55 ring-1 ring-white/10 transition-colors hover:bg-white/5 hover:text-white/85"
                                >
                                    {s.label}
                                </button>
                            ))}
                        </div>
                        {shared ? (
                            <p className="border-t border-white/[0.06] px-2.5 pb-2 pt-2.5 text-[11px] leading-relaxed text-white/35">
                                Plays the default sounds when a device on this map goes down or comes back. Saved in this browser.
                            </p>
                        ) : (
                            onOpenSettings && (
                                <button
                                    onClick={() => {
                                        setOpen(false);
                                        onOpenSettings();
                                    }}
                                    className="flex w-full items-center gap-2 rounded-xl border-t border-white/[0.06] px-2.5 py-2.5 text-left text-[12.5px] text-white/75 transition-colors hover:bg-white/5 hover:text-white"
                                >
                                    <GearSix weight="light" className="h-4 w-4 text-white/40" />
                                    Sound settings
                                    <CaretRight weight="bold" className="ml-auto h-3.5 w-3.5 text-white/35" />
                                </button>
                            )
                        )}
                    </div>,
                document.body,
            )}
        </div>
    );
}
