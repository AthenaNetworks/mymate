import { useSyncExternalStore } from 'react';
import type { AlertConditionType } from '../types';

/**
 * Alert sounds: a short sound (and optionally a spoken line and a desktop notification) when a
 * device goes down or comes back, or when an alert fires or resolves. Everything here is
 * per-browser - it's about the speaker in the room, so muting one desk never mutes another.
 *
 * Two contexts share the engine:
 *  - `app`: a signed-in operator, on any page and in wallboard mode. Fed by the live channel
 *    (hooks/useAlertSounds) and configured in Settings > Sounds.
 *  - `shared`: a public wallboard link. Starts silent; its speaker button only turns sound on/off
 *    and mutes, and it always plays the default sounds for devices going down or coming back
 *    (it has no live channel, so PublicWallboard spots the changes between polls).
 *
 * The sounds are synthesised with the Web Audio API, so there are no audio files to ship. A
 * browser won't play sound until the page has had a click/tap/key press, so the engine unlocks
 * itself on the first one and the speaker button shows an amber dot until then.
 *
 * With several My Mate tabs open, only one plays: the first tab to be unlocked takes a Web Lock
 * and holds it until it closes, then the next one takes over. Without Web Locks (plain HTTP)
 * every tab plays.
 */

export type SoundSource = 'devices' | 'alerts';
/** One list for both directions, so anyone can pick whichever sound they like for each. */
export type AlertSound = 'off' | 'alarm' | 'rising' | 'beeps' | 'chime' | 'ding' | 'knock' | 'soft' | 'soft-up';

// "No sound" first, then alphabetical by name. Values stay stable for saved settings ('soft' was
// "Soft chime" before it had an "up" twin).
export const ALERT_SOUNDS: { value: AlertSound; label: string }[] = [
    { value: 'off', label: 'No sound' },
    { value: 'alarm', label: 'Alarm' },
    { value: 'rising', label: 'All clear' },
    { value: 'beeps', label: 'Beeps' },
    { value: 'chime', label: 'Chime' },
    { value: 'ding', label: 'Ding' },
    { value: 'knock', label: 'Knock' },
    { value: 'soft', label: 'Soft chime down' },
    { value: 'soft-up', label: 'Soft chime up' },
];

/** The alert policy conditions, in the policy editor's order, as the Sounds tab lists them. */
export const SOUND_ALERT_TYPES: { value: AlertConditionType; label: string }[] = [
    { value: 'device_down', label: 'Device down / recovered' },
    { value: 'interface_down', label: 'Interface / port down' },
    { value: 'agent_down', label: 'Remote agent offline' },
    { value: 'probe_down', label: 'Service probe down' },
    { value: 'probe_slow', label: 'Service probe slow' },
    { value: 'high_util', label: 'Sustained high utilisation' },
    { value: 'low_throughput', label: 'Low link throughput' },
    { value: 'high_metric', label: 'High CPU / memory / temperature' },
    { value: 'optical_power', label: 'Fibre optical power' },
    { value: 'backup_failed', label: 'Config backup failed' },
    { value: 'upgrade_failed', label: 'Upgrade failed' },
    { value: 'new_discovery', label: 'New device discovered' },
];

export interface SoundPrefs {
    enabled: boolean;
    mutedUntil: number; // epoch ms; 0 = not snoozed
    source: SoundSource;
    alertTypes: AlertConditionType[];
    firingSound: AlertSound; // down / firing
    resolvedSound: AlertSound; // back up / resolved
    volume: number; // 0-100, the sounds
    speak: boolean;
    speechVolume: number; // 0-100, the spoken line
    notify: boolean;
}

/** What "Reset to defaults" puts back (not on/off or a snooze - those belong to the speaker button). */
export const SOUND_DEFAULTS = {
    source: 'devices' as SoundSource,
    alertTypes: ['device_down'] as AlertConditionType[],
    firingSound: 'soft' as AlertSound,
    resolvedSound: 'soft-up' as AlertSound,
    volume: 70,
    speechVolume: 80,
};

// Off until someone turns it on with the speaker button: a new feature shouldn't start making noise.
const APP_DEFAULTS: SoundPrefs = { enabled: false, mutedUntil: 0, speak: false, notify: false, ...SOUND_DEFAULTS };
// A shared wallboard link starts silent too.
const SHARED_DEFAULTS: SoundPrefs = { ...APP_DEFAULTS, enabled: false };

export type SoundContext = 'app' | 'shared';
const KEY: Record<SoundContext, string> = { app: 'mymate.alertSounds', shared: 'mymate.alertSounds.wall' };

const clamp = (n: unknown, d: number) => (typeof n === 'number' && Number.isFinite(n) ? Math.min(100, Math.max(0, Math.round(n))) : d);
const oneOf = <T extends string>(v: unknown, opts: { value: T }[], d: T): T => (opts.some((o) => o.value === v) ? (v as T) : d);

function read(ctx: SoundContext): SoundPrefs {
    const d = ctx === 'app' ? APP_DEFAULTS : SHARED_DEFAULTS;
    let raw: Partial<SoundPrefs> = {};
    try {
        raw = JSON.parse(localStorage.getItem(KEY[ctx]) ?? '{}') ?? {};
    } catch {
        /* blocked or corrupt storage: defaults */
    }
    return {
        enabled: typeof raw.enabled === 'boolean' ? raw.enabled : d.enabled,
        mutedUntil: typeof raw.mutedUntil === 'number' ? raw.mutedUntil : 0,
        source: raw.source === 'alerts' ? 'alerts' : 'devices',
        alertTypes: Array.isArray(raw.alertTypes)
            ? raw.alertTypes.filter((t): t is AlertConditionType => SOUND_ALERT_TYPES.some((o) => o.value === t))
            : [...d.alertTypes],
        firingSound: oneOf(raw.firingSound, ALERT_SOUNDS, d.firingSound),
        resolvedSound: oneOf(raw.resolvedSound, ALERT_SOUNDS, d.resolvedSound),
        volume: clamp(raw.volume, d.volume),
        speak: typeof raw.speak === 'boolean' ? raw.speak : d.speak,
        speechVolume: clamp(raw.speechVolume, d.speechVolume),
        notify: typeof raw.notify === 'boolean' ? raw.notify : d.notify,
    };
}

const prefs: Record<SoundContext, SoundPrefs> = { app: read('app'), shared: read('shared') };
let unlocked = false;
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((l) => l());

function subscribe(l: () => void) {
    listeners.add(l);
    return () => listeners.delete(l);
}

// Another tab changed the settings (or unmuted): follow it, so the tab that plays obeys.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (e) => {
        if (e.key === KEY.app || e.key === KEY.shared) {
            prefs.app = read('app');
            prefs.shared = read('shared');
            emit();
        }
    });
}

export function setSoundPrefs(ctx: SoundContext, patch: Partial<SoundPrefs>): void {
    prefs[ctx] = { ...prefs[ctx], ...patch };
    try {
        localStorage.setItem(KEY[ctx], JSON.stringify(prefs[ctx]));
    } catch {
        /* kept for this page only */
    }
    emit();
}

export function useSoundPrefs(ctx: SoundContext): SoundPrefs {
    return useSyncExternalStore(subscribe, () => prefs[ctx]);
}

/** The current settings, for event handlers that run outside React. */
export function getSoundPrefsSnapshot(ctx: SoundContext): SoundPrefs {
    return prefs[ctx];
}

/** False until the page has had the click/tap/key press a browser needs before it plays sound. */
export function useAudioUnlocked(): boolean {
    return useSyncExternalStore(subscribe, () => unlocked);
}

/** Muted right now: switched off, or snoozed until a time still ahead. */
export function isSoundMuted(p: SoundPrefs, now = Date.now()): boolean {
    return !p.enabled || p.mutedUntil > now;
}

// --- Synthesis ------------------------------------------------------------------------------

let audioCtx: AudioContext | null = null;
let master: GainNode | null = null;

function audio(): AudioContext | null {
    if (!audioCtx) {
        const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
        if (!Ctor) return null;
        try {
            audioCtx = new Ctor();
            master = audioCtx.createGain();
            master.connect(audioCtx.destination);
        } catch {
            return null;
        }
    }
    if (audioCtx.state === 'suspended') void audioCtx.resume().catch(() => {});
    return audioCtx;
}

function tone(volume: number, freqs: number[], gap: number, peak: number, type: OscillatorType = 'sine', len = 0.55) {
    const a = audio();
    if (!a || !master) return;
    master.gain.value = volume / 100;
    freqs.forEach((f, i) => {
        const t = a.currentTime + i * gap;
        const o = a.createOscillator();
        const g = a.createGain();
        o.type = type;
        o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(peak, t + 0.015);
        g.gain.exponentialRampToValueAtTime(0.0001, t + len);
        o.connect(g).connect(master!);
        o.start(t);
        o.stop(t + len + 0.05);
    });
}

// Peaks are balanced by ear so the sounds come out about equally loud at the same volume.
const SYNTH: Record<AlertSound, (volume: number) => void> = {
    chime: (v) => tone(v, [880, 1318.5], 0.16, 0.36),
    alarm: (v) => tone(v, [987.8, 784, 987.8, 784, 987.8, 784], 0.2, 0.17, 'triangle', 0.19),
    beeps: (v) => tone(v, [1000, 1000, 1000], 0.18, 0.21, 'square', 0.1),
    knock: (v) => tone(v, [196, 196], 0.17, 0.7, 'sine', 0.12),
    off: () => {},
    soft: (v) => tone(v, [659.3, 523.3], 0.14, 0.17),
    'soft-up': (v) => tone(v, [523.3, 659.3], 0.14, 0.17),
    rising: (v) => tone(v, [523.3, 659.3, 784], 0.12, 0.2, 'sine', 0.5),
    ding: (v) => tone(v, [1568], 0, 0.2, 'sine', 1.4),
};

/** Play one sound now (the Sounds tab's pickers and test button use this directly). */
export function playSound(sound: AlertSound, volume: number): void {
    SYNTH[sound](volume);
}

export function speakLine(text: string, volume: number): void {
    try {
        if (!('speechSynthesis' in window)) return;
        window.speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance(text);
        u.rate = 1.05;
        u.volume = volume / 100;
        window.speechSynthesis.speak(u);
    } catch {
        /* no speech on this browser */
    }
}

// --- Unlock + one tab only --------------------------------------------------------------------

let leader = typeof navigator === 'undefined' || !navigator.locks; // no Web Locks: every tab plays
let lockContext: SoundContext | null = null;

function takeLead(ctx: SoundContext) {
    if (!navigator.locks || lockContext) return;
    lockContext = ctx;
    // Held for the life of the tab; queued tabs get it in turn as earlier ones close.
    void navigator.locks
        .request(`mymate-alert-sounds-${ctx}`, () => {
            leader = true;
            return new Promise<void>(() => {});
        })
        .catch(() => {
            leader = true;
        });
}

/**
 * Arm the engine for this page. Sound can only start inside a user gesture, so the first
 * click/tap/key press resumes the audio context (and primes speech on iOS); that same gesture
 * puts this tab in the queue to be the one that plays.
 */
export function armAlertSounds(ctx: SoundContext): () => void {
    const onGesture = () => {
        if (unlocked) return;
        const a = audio();
        if (a) {
            // A silent blip inside the gesture is what finally unlocks Safari.
            const b = a.createBuffer(1, 1, 22050);
            const s = a.createBufferSource();
            s.buffer = b;
            s.connect(a.destination);
            s.start(0);
        }
        try {
            if (prefs[ctx].speak && 'speechSynthesis' in window) window.speechSynthesis.speak(new SpeechSynthesisUtterance(''));
        } catch {
            /* ignore */
        }
        unlocked = true;
        takeLead(ctx);
        emit();
    };
    const opts = { capture: true } as const;
    window.addEventListener('pointerdown', onGesture, opts);
    window.addEventListener('keydown', onGesture, opts);
    window.addEventListener('touchend', onGesture, opts);
    return () => {
        window.removeEventListener('pointerdown', onGesture, opts);
        window.removeEventListener('keydown', onGesture, opts);
        window.removeEventListener('touchend', onGesture, opts);
    };
}

// --- Announcing -----------------------------------------------------------------------------

/** Anything arriving within this long shares one sound, one spoken line and one notification. */
export const SOUND_BURST_MS = 3000;

export interface SoundEvent {
    dir: 'down' | 'up';
    /** Spoken and notified when it's the only one in its burst: "edge-gw-01 is down". */
    line: string;
    /** What a burst of several is made of, for "25 devices down" / "3 alerts firing". */
    noun: 'device' | 'alert';
}

const buffers: Record<SoundContext, { down: SoundEvent[]; up: SoundEvent[]; timer: ReturnType<typeof setTimeout> | null }> = {
    app: { down: [], up: [], timer: null },
    shared: { down: [], up: [], timer: null },
};

function summary(events: SoundEvent[], dir: 'down' | 'up'): string {
    if (events.length === 1) return events[0].line;
    const alerts = events.every((e) => e.noun === 'alert');
    if (alerts) return `${events.length} alerts ${dir === 'down' ? 'firing' : 'resolved'}`;
    return `${events.length} ${dir === 'down' ? 'devices down' : 'back up'}`;
}

function flush(ctx: SoundContext) {
    const buf = buffers[ctx];
    const { down, up } = buf;
    buf.down = [];
    buf.up = [];
    buf.timer = null;

    const p = prefs[ctx];
    if (!leader || isSoundMuted(p)) return;
    // A shared wallboard always plays the defaults and never speaks or notifies.
    const shared = ctx === 'shared';
    const firing = shared ? SOUND_DEFAULTS.firingSound : p.firingSound;
    const resolved = shared ? SOUND_DEFAULTS.resolvedSound : p.resolvedSound;
    const volume = shared ? SOUND_DEFAULTS.volume : p.volume;

    // "No sound" for a direction keeps that direction quiet: no sound, no spoken line.
    const playDown = down.length > 0 && firing !== 'off';
    const playUp = up.length > 0 && resolved !== 'off';
    const lines: string[] = [];
    if (playDown) {
        playSound(firing, volume);
        lines.push(summary(down, 'down'));
    }
    if (playUp) {
        // After a down sound, wait for it to finish rather than play over it.
        if (playDown) setTimeout(() => playSound(resolved, volume), 1300);
        else playSound(resolved, volume);
        lines.push(summary(up, 'up'));
    }
    if (shared || lines.length === 0) return;

    if (p.speak) {
        const text = lines.join('. ');
        // Let the sound play first.
        setTimeout(() => speakLine(text, p.speechVolume), playDown && playUp ? 2200 : 900);
    }
    if (p.notify && typeof Notification !== 'undefined' && Notification.permission === 'granted') {
        try {
            new Notification(lines[0], { body: lines.slice(1).join('\n') || 'My Mate', tag: 'mymate-alert-sounds' });
        } catch {
            /* some browsers only allow notifications from a service worker */
        }
    }
}

/** Queue a sound for this burst. Callers decide what counts (source, alert types, real flips). */
export function announce(ctx: SoundContext, e: SoundEvent): void {
    const buf = buffers[ctx];
    buf[e.dir].push(e);
    if (buf.timer === null) buf.timer = setTimeout(() => flush(ctx), SOUND_BURST_MS);
}

/** Ask for desktop notification permission. Resolves to whether they're allowed. */
export async function requestNotifyPermission(): Promise<boolean> {
    if (typeof Notification === 'undefined') return false;
    if (Notification.permission === 'granted') return true;
    try {
        return (await Notification.requestPermission()) === 'granted';
    } catch {
        return false;
    }
}
