import { useMemo, useState } from 'react';
import { CaretLeft, CaretRight, ClockCounterClockwise, Pause, Play, X } from '@phosphor-icons/react';
import { PLAYBACK_PRESETS, PLAYBACK_SPEEDS, type Playback } from '../hooks/usePlayback';
import { frameAt } from '../lib/playbackFrame';

/** "Tue 24 Sep, 14:32" in the viewer's own zone. */
export function formatFrameTime(unixSeconds: number): string {
    return new Date(unixSeconds * 1000).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function formatStep(seconds: number): string {
    if (seconds % 3600 === 0) return `${seconds / 3600}h`;
    if (seconds % 60 === 0) return `${seconds / 60} min`;
    return `${seconds}s`;
}

// unix ms <-> the naive local value a datetime-local input wants
const toLocalInput = (ms: number) => new Date(ms - new Date(ms).getTimezoneOffset() * 60000).toISOString().slice(0, 16);

const glass = 'bg-black/60 ring-1 ring-white/15 backdrop-blur';
const btn = 'grid h-7 w-7 place-items-center rounded-lg text-white/70 hover:bg-white/10 hover:text-white disabled:opacity-30 disabled:hover:bg-transparent';

/**
 * LIVE / PLAYBACK indicator for the canvas's top-left (GitHub #22). Live: a quiet pill plus the
 * button that opens playback. Playback: an amber pill with the frame's time and a way back.
 */
export function PlaybackBadge({ pb }: { pb: Playback }) {
    if (!pb.active) {
        return (
            <div className={`flex items-center gap-1 rounded-lg px-1 py-1 ${glass}`}>
                <span className="flex items-center gap-1.5 px-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-300">
                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" /> Live
                </span>
                <button
                    type="button"
                    disabled={!pb.canStart}
                    onClick={() => pb.load(24)}
                    title="Play back how the map looked over a past window"
                    className="flex items-center gap-1.5 rounded-md px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/60 hover:bg-white/10 hover:text-white disabled:opacity-40"
                >
                    <ClockCounterClockwise weight="bold" className="h-3.5 w-3.5" /> History
                </button>
            </div>
        );
    }
    const t = pb.store?.frames[pb.frame];
    return (
        <div className="flex items-center gap-1 rounded-lg bg-amber-500/20 px-1 py-1 ring-1 ring-amber-400/50 backdrop-blur">
            <span className="px-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-200">
                Playback{t !== undefined ? ` ${formatFrameTime(t)}` : ''}
            </span>
            <button
                type="button"
                onClick={pb.exit}
                title="Back to the live map"
                className="flex items-center gap-1 rounded-md px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-100/80 hover:bg-white/10 hover:text-white"
            >
                <X weight="bold" className="h-3 w-3" /> Live
            </button>
        </div>
    );
}

/**
 * The playback controls along the bottom of the geo map: window presets and a date/time jump,
 * play/pause/step/speed, and a scrubber with red ticks where devices went down and a shade for
 * the frames that have loaded so far.
 */
export function PlaybackBar({ pb, deviceName }: { pb: Playback; deviceName: (id: number) => string }) {
    const { store, window: win } = pb;
    const [jump, setJump] = useState('');

    const n = store?.frames.length ?? 0;
    const t0 = store ? store.frames[0] : 0;
    const t1 = store ? store.frames[n - 1] + store.step : 1;
    const pct = (t: number) => Math.max(0, Math.min(100, ((t - t0) / (t1 - t0)) * 100));

    // runs of loaded frames, drawn as one shaded bar each
    const loadedRuns = useMemo(() => {
        if (!store) return [];
        const runs: Array<[number, number]> = [];
        let startAt: number | null = null;
        store.loaded.forEach((ok, i) => {
            if (ok && startAt === null) startAt = i;
            if (!ok && startAt !== null) {
                runs.push([startAt, i]);
                startAt = null;
            }
        });
        if (startAt !== null) runs.push([startAt, store.loaded.length]);
        return runs;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [store, pb.version]);

    const downNow = store?.downAt[pb.frame]?.size ?? 0;

    return (
        <div className={`absolute inset-x-4 bottom-6 z-10 rounded-xl px-3 py-2 ${glass}`}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <div className="flex items-center gap-0.5">
                    <button type="button" className={btn} onClick={() => pb.step(-1)} disabled={!store || pb.frame <= 0} title="Previous frame">
                        <CaretLeft weight="bold" className="h-4 w-4" />
                    </button>
                    <button type="button" className={btn} onClick={pb.togglePlay} disabled={!store} title={pb.playing ? 'Pause' : 'Play'}>
                        {pb.playing ? <Pause weight="fill" className="h-4 w-4" /> : <Play weight="fill" className="h-4 w-4" />}
                    </button>
                    <button type="button" className={btn} onClick={() => pb.step(1)} disabled={!store || pb.frame >= n - 1} title="Next frame">
                        <CaretRight weight="bold" className="h-4 w-4" />
                    </button>
                </div>

                <div className="min-w-0 text-xs">
                    {store ? (
                        <>
                            <span className="font-semibold text-white">{formatFrameTime(store.frames[pb.frame])}</span>
                            <span className="ml-2 text-white/40">
                                {formatStep(store.step)} frame, {pb.frame + 1}/{n}
                            </span>
                            {downNow > 0 && <span className="ml-2 text-red-300">{downNow} down</span>}
                            {!pb.frameLoaded && <span className="ml-2 text-amber-200/80">loading traffic...</span>}
                        </>
                    ) : pb.error ? (
                        <span className="text-red-300">Couldn't load history: {pb.error}</span>
                    ) : (
                        <span className="text-white/50">Loading history...</span>
                    )}
                </div>

                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <div className="flex items-center rounded-lg ring-1 ring-white/10">
                        {PLAYBACK_SPEEDS.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => pb.setSpeed(s)}
                                className={`px-2 py-1 text-[10px] font-semibold ${pb.speed === s ? 'bg-white/15 text-white' : 'text-white/50 hover:text-white'}`}
                                title={`Play at ${s}x`}
                            >
                                {s}x
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center rounded-lg ring-1 ring-white/10">
                        {PLAYBACK_PRESETS.map((p) => (
                            <button
                                key={p.label}
                                type="button"
                                onClick={() => pb.load(p.hours)}
                                className={`px-2 py-1 text-[10px] font-semibold uppercase ${win?.hours === p.hours && win.focus === null ? 'bg-emerald-500/20 text-emerald-200' : 'text-white/50 hover:text-white'}`}
                                title={`The last ${p.label}`}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>
                    <form
                        className="flex items-center gap-1"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const at = jump ? new Date(jump).getTime() : NaN;
                            if (Number.isFinite(at)) pb.load(win?.hours ?? 24, Math.min(at, Date.now()));
                        }}
                    >
                        <input
                            type="datetime-local"
                            value={jump}
                            max={toLocalInput(Date.now())}
                            onChange={(e) => setJump(e.target.value)}
                            className="w-44 rounded-lg bg-white/[0.04] px-2 py-1 text-xs text-white ring-1 ring-white/10 outline-none focus:ring-2 focus:ring-emerald-400/60"
                            title="Jump to a moment: opens the window centred on it"
                        />
                        <button type="submit" disabled={!jump} className="rounded-lg px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/60 ring-1 ring-white/10 hover:text-white disabled:opacity-40">
                            Go
                        </button>
                    </form>
                </div>
            </div>

            {/* Scrubber: loaded frames shaded, outages as red marks (their width is how long they
                lasted), the native range input on top for dragging and arrow keys. */}
            <div className="relative mt-2 h-6">
                <div className="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-white/10">
                    {loadedRuns.map(([a, b]) => (
                        <div key={a} className="absolute inset-y-0 rounded-full bg-white/25" style={{ left: `${(a / n) * 100}%`, width: `${((b - a) / n) * 100}%` }} />
                    ))}
                </div>
                {store &&
                    store.outages.map(([id, start, end], k) => {
                        const left = pct(start);
                        const width = Math.max(0.3, pct(end ?? t1) - left);
                        return (
                            <button
                                key={k}
                                type="button"
                                onClick={() => pb.setFrame(frameAt(store, start))}
                                title={`${deviceName(id)} down ${formatFrameTime(start)}${end ? ` for ${Math.max(1, Math.round((end - start) / 60))} min` : ', still down'}`}
                                className="absolute top-0 z-10 h-2 min-w-[2px] rounded-sm bg-red-500/80 hover:bg-red-400"
                                style={{ left: `${left}%`, width: `${width}%` }}
                            />
                        );
                    })}
                <input
                    type="range"
                    min={0}
                    max={Math.max(0, n - 1)}
                    value={pb.frame}
                    disabled={!store}
                    onChange={(e) => pb.setFrame(Number(e.target.value))}
                    className="absolute inset-x-0 top-1/2 w-full -translate-y-1/2 cursor-pointer accent-amber-400"
                    aria-label="Playback position"
                />
            </div>
            {store && (
                <div className="flex justify-between text-[10px] text-white/35">
                    <span>{formatFrameTime(t0)}</span>
                    <span>{formatFrameTime(store.frames[n - 1])}</span>
                </div>
            )}
        </div>
    );
}
