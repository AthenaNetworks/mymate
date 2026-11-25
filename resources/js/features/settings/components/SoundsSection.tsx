import { useState } from 'react';
import { BellRinging, Microphone, Play, SpeakerHigh, SpeakerSimpleHigh } from '@phosphor-icons/react';
import {
    ALERT_SOUNDS,
    playSound,
    requestNotifyPermission,
    setSoundPrefs,
    SOUND_ALERT_TYPES,
    SOUND_DEFAULTS,
    speakLine,
    useSoundPrefs,
    type AlertSound,
    type SoundSource,
} from '../../../lib/alertSound';
import { Toggle } from '../../../components/Toggle';

const card = 'min-w-0 rounded-2xl bg-white/[0.02] p-5 ring-1 ring-white/[0.06]';
const select =
    'w-36 shrink-0 rounded-xl bg-white/[0.03] px-3 py-2 text-sm text-white ring-1 ring-white/10 outline-none transition focus:ring-2 focus:ring-emerald-400/60';

function CardHead({ icon: Icon, title, children }: { icon: typeof SpeakerHigh; title: string; children: React.ReactNode }) {
    return (
        <div className="mb-4 flex items-start gap-2">
            <Icon weight="light" className="mt-0.5 h-4 w-4 shrink-0 text-white/40" />
            <div>
                <h2 className="text-sm font-bold text-white">{title}</h2>
                <p className="text-xs text-white/40">{children}</p>
            </div>
        </div>
    );
}

/**
 * A 0-100 slider that applies as you drag and plays a sample when you let go, so the level can
 * be set by ear.
 */
function VolumeSlider({ id, value, onChange, onRelease, disabled, icon: Icon }: {
    id: string;
    value: number;
    onChange: (v: number) => void;
    onRelease: (v: number) => void;
    disabled?: boolean;
    icon: typeof SpeakerHigh;
}) {
    return (
        <span className={`flex w-56 max-w-[55%] shrink-0 items-center gap-2.5 ${disabled ? 'opacity-45' : ''}`}>
            <Icon weight="light" className="h-4 w-4 shrink-0 text-white/40" />
            <input
                id={id}
                type="range"
                min={0}
                max={100}
                step={5}
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(Number(e.target.value))}
                onPointerUp={(e) => onRelease(Number((e.target as HTMLInputElement).value))}
                onKeyUp={(e) => onRelease(Number((e.target as HTMLInputElement).value))}
                className="min-w-0 flex-1 accent-emerald-500"
            />
            <output htmlFor={id} className="w-10 text-right font-mono text-xs tabular-nums text-white/55">
                {value}%
            </output>
        </span>
    );
}

/** One direction's sound: the list, and a play button to hear the current choice again. */
function SoundPicker({ id, label, value, volume, onChange }: {
    id: string;
    label: string;
    value: AlertSound;
    volume: number;
    onChange: (v: AlertSound) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <label htmlFor={id} className="min-w-0 flex-1 text-sm text-white/70">
                {label}
            </label>
            <div className="flex shrink-0 items-center gap-1.5">
                <select
                    id={id}
                    value={value}
                    onChange={(e) => {
                        const v = e.target.value as AlertSound;
                        onChange(v);
                        playSound(v, volume);
                    }}
                    className={select}
                >
                    {ALERT_SOUNDS.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={() => playSound(value, volume)}
                    disabled={value === 'off'}
                    title={value === 'off' ? 'No sound to play' : `Play "${ALERT_SOUNDS.find((s) => s.value === value)?.label}"`}
                    aria-label={`Play the sound for: ${label}`}
                    className="grid h-9 w-9 place-items-center rounded-xl text-emerald-300 ring-1 ring-white/10 transition-colors hover:bg-emerald-500/10 hover:text-emerald-200 disabled:text-white/25 disabled:hover:bg-transparent"
                >
                    <Play weight="fill" className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}

/**
 * Settings > Sounds: what plays a sound, which sounds, how loud, and the spoken / desktop
 * extras. Per-browser, applied as you change it (no save). Turning sound on or off and muting
 * for a while live on the speaker button in the top bar instead.
 */
export function SoundsSection() {
    const p = useSoundPrefs('app');
    const set = (patch: Parameters<typeof setSoundPrefs>[1]) => setSoundPrefs('app', patch);
    const [notifyNote, setNotifyNote] = useState<string | null>(null);
    const devices = p.source === 'devices';

    const isDefault =
        p.source === SOUND_DEFAULTS.source &&
        p.alertTypes.length === SOUND_DEFAULTS.alertTypes.length &&
        SOUND_DEFAULTS.alertTypes.every((t) => p.alertTypes.includes(t)) &&
        p.firingSound === SOUND_DEFAULTS.firingSound &&
        p.resolvedSound === SOUND_DEFAULTS.resolvedSound &&
        p.volume === SOUND_DEFAULTS.volume &&
        p.speechVolume === SOUND_DEFAULTS.speechVolume;

    const sourceBtn = (value: SoundSource, label: string, sub: string) => (
        <button
            type="button"
            onClick={() => set({ source: value })}
            aria-pressed={p.source === value}
            className={`rounded-[10px] px-3 py-2.5 text-left text-[13px] leading-snug transition-colors ${
                p.source === value ? 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/30' : 'text-white/55 hover:text-white/80'
            }`}
        >
            {label}
            <span className="mt-0.5 block text-[11px] text-white/35">{sub}</span>
        </button>
    );

    async function toggleNotify(on: boolean) {
        if (!on) {
            set({ notify: false });
            setNotifyNote(null);
            return;
        }
        if (typeof Notification === 'undefined' || !window.isSecureContext) {
            setNotifyNote('This browser can\'t show notifications here. My Mate has to be on HTTPS, and on an iPad added to the Home Screen.');
            return;
        }
        if (await requestNotifyPermission()) {
            set({ notify: true });
            setNotifyNote(null);
        } else {
            setNotifyNote('The browser has blocked notifications for this site. Allow them in the browser\'s site settings, then try again.');
        }
    }

    return (
        <div className="grid min-w-0 grid-cols-1 items-start gap-6 lg:grid-cols-2">
            <div className="grid min-w-0 gap-6">
                <section className={card}>
                    <CardHead icon={BellRinging} title="What plays a sound">
                        Sounds play on every page while you're signed in, including wallboard mode. Saved in this browser.
                    </CardHead>
                    <div className="grid grid-cols-2 gap-1 rounded-xl bg-white/[0.04] p-1 ring-1 ring-white/[0.06]">
                        {sourceBtn('devices', 'Any device down or back up', 'Every monitored device')}
                        {sourceBtn('alerts', 'Alerts from alert policies', 'Only the types you tick')}
                    </div>

                    {!devices && (
                        <div className="mt-4">
                            <div className="mb-1.5 flex items-baseline gap-2.5 text-[13px] text-white/70">
                                Alert types
                                <span className="text-xs text-white/35">
                                    {p.alertTypes.length} of {SOUND_ALERT_TYPES.length} ticked
                                </span>
                                <span className="ml-auto flex gap-3 text-xs">
                                    <button type="button" onClick={() => set({ alertTypes: SOUND_ALERT_TYPES.map((t) => t.value) })} className="text-emerald-300 hover:text-emerald-200">
                                        Tick all
                                    </button>
                                    <button type="button" onClick={() => set({ alertTypes: [...SOUND_DEFAULTS.alertTypes] })} className="text-emerald-300 hover:text-emerald-200">
                                        Back to default
                                    </button>
                                </span>
                            </div>
                            <div className="grid grid-cols-1 gap-x-3 gap-y-0.5 rounded-xl bg-white/[0.02] p-1.5 ring-1 ring-white/[0.06] sm:grid-cols-2">
                                {SOUND_ALERT_TYPES.map((t) => (
                                    <label key={t.value} className="flex cursor-pointer items-center gap-2 rounded-lg p-1.5 text-[12.5px] text-white/75 hover:bg-white/[0.04]">
                                        <input
                                            type="checkbox"
                                            checked={p.alertTypes.includes(t.value)}
                                            onChange={(e) =>
                                                set({
                                                    alertTypes: e.target.checked
                                                        ? SOUND_ALERT_TYPES.map((o) => o.value).filter((v) => v === t.value || p.alertTypes.includes(v))
                                                        : p.alertTypes.filter((v) => v !== t.value),
                                                })
                                            }
                                            className="h-3.5 w-3.5 shrink-0 accent-emerald-500"
                                        />
                                        {t.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}
                    <p className="mt-3 text-xs leading-relaxed text-white/40">
                        {devices ? (
                            'Every monitored device you can see, whether or not an alert policy covers it.'
                        ) : p.alertTypes.length === 0 ? (
                            <span className="text-amber-300">Tick at least one alert type, or nothing will sound.</span>
                        ) : (
                            'Only alerts your alert policies raise, and only for devices you can see.'
                        )}
                    </p>
                </section>

                <section className={card}>
                    <CardHead icon={Microphone} title="Spoken and desktop alerts">
                        Extras on top of the sound.
                    </CardHead>
                    <div className="space-y-3">
                        <label className="flex items-center justify-between gap-4">
                            <span className="min-w-0 flex-1 text-sm text-white/70">
                                Read alerts out loud
                                <span className="block text-xs text-white/40">"edge-gw-01 is down", after the sound.</span>
                            </span>
                            <Toggle
                                checked={p.speak}
                                onChange={(on) => {
                                    set({ speak: on });
                                    if (on) speakLine('Alerts will be read out loud', p.speechVolume);
                                }}
                                label="Read alerts out loud"
                            />
                        </label>
                        <div className="flex items-center justify-between gap-4">
                            <label htmlFor="sound-speech-volume" className={`min-w-0 flex-1 text-sm text-white/70 ${p.speak ? '' : 'opacity-45'}`}>
                                Voice volume
                                <span className="block text-xs text-white/40">Letting go reads a sample line.</span>
                            </label>
                            <VolumeSlider
                                id="sound-speech-volume"
                                icon={Microphone}
                                value={p.speechVolume}
                                disabled={!p.speak}
                                onChange={(v) => set({ speechVolume: v })}
                                onRelease={(v) => speakLine('edge-gw-01 is down', v)}
                            />
                        </div>
                        <div className="border-t border-white/5 pt-3">
                            <label className="flex items-center justify-between gap-4">
                                <span className="min-w-0 flex-1 text-sm text-white/70">
                                    Desktop notifications
                                    <span className="block text-xs text-white/40">
                                        Pops up even when this tab is in the background. Needs My Mate on HTTPS; on an iPad, only once it's added
                                        to the Home Screen.
                                    </span>
                                    {notifyNote && <span className="mt-1 block text-xs text-amber-300">{notifyNote}</span>}
                                </span>
                                <Toggle checked={p.notify} onChange={(on) => void toggleNotify(on)} label="Desktop notifications" />
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            <section className={card}>
                <CardHead icon={SpeakerHigh} title="Sounds">
                    Picking a sound plays it, and so does its play button. Turn sound on or off and mute it from the speaker button in the top bar.
                </CardHead>
                <div className="space-y-3">
                    <SoundPicker
                        id="sound-down"
                        label={devices ? 'When a device goes down' : 'When an alert fires'}
                        value={p.firingSound}
                        volume={p.volume}
                        onChange={(v) => set({ firingSound: v })}
                    />
                    <SoundPicker
                        id="sound-up"
                        label={devices ? "When it's back up" : "When it's resolved"}
                        value={p.resolvedSound}
                        volume={p.volume}
                        onChange={(v) => set({ resolvedSound: v })}
                    />
                    <div className="flex items-center justify-between gap-4 border-t border-white/5 pt-3">
                        <label htmlFor="sound-volume" className="min-w-0 flex-1 text-sm text-white/70">
                            Volume
                            <span className="block text-xs text-white/40">
                                For the sounds above. Spoken alerts have their own volume. The device's own volume still sets the top end.
                            </span>
                        </label>
                        <VolumeSlider
                            id="sound-volume"
                            icon={SpeakerSimpleHigh}
                            value={p.volume}
                            onChange={(v) => set({ volume: v })}
                            onRelease={(v) => playSound(p.firingSound !== 'off' ? p.firingSound : p.resolvedSound, v)}
                        />
                    </div>
                </div>
                <div className="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => set({ ...SOUND_DEFAULTS, alertTypes: [...SOUND_DEFAULTS.alertTypes] })}
                        disabled={isDefault}
                        className="rounded-lg px-3 py-1.5 text-sm text-white/60 ring-1 ring-white/10 hover:bg-white/5 hover:text-white/85 disabled:opacity-40"
                    >
                        Reset to defaults
                    </button>
                </div>
            </section>
        </div>
    );
}
