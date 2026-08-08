import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowClockwise, CircleNotch, Path, Stop, WarningCircle, X } from '@phosphor-icons/react';
import { stopTrace, useStartTrace, useTraceRun, type TraceHop, type TraceRun } from '../api/deviceTrace';

const DEFAULT_ROUNDS = 60;
const ROUND_CHOICES = [20, 60, 120];

// Sub-millisecond precision only matters on the near hops; past 100 ms the decimal is noise.
function fmtMs(ms: number | null): string {
    if (ms === null) return '-';
    return ms >= 100 ? ms.toFixed(0) : ms.toFixed(1);
}

// Loss ramp: clean / degraded / bad. A single dropped probe out of sixty is normal on
// rate-limited routers, so only sustained loss goes red.
function lossClass(hop: TraceHop): string {
    if (hop.sent === 0) return 'text-white/25';
    if (hop.loss_pct <= 0) return 'text-emerald-300';
    if (hop.loss_pct < 20) return 'text-amber-300';
    return 'text-rose-300';
}

// The latency bar is scaled to the slowest hop in this trace, so the shape of the path
// (where the jump happens) reads at a glance instead of having to compare numbers.
function barClass(ratio: number): string {
    if (ratio < 0.34) return 'bg-emerald-400/25';
    if (ratio < 0.67) return 'bg-amber-400/25';
    return 'bg-rose-400/25';
}

const th = 'whitespace-nowrap px-3 py-2 text-right font-medium';
const td = 'whitespace-nowrap px-3 py-1.5 text-right font-mono tabular-nums';

const ctrlBtn =
    'inline-flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-2.5 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white disabled:cursor-not-allowed disabled:opacity-40';

/** Host cell: the PTR name when mtr resolved one, the address underneath it in mono.
 *  A hop that never answered keeps its slot as MTR's pulsing `* * *` - dropping the row
 *  would silently renumber the path. */
function HostCell({ hop, isTarget }: { hop: TraceHop; isTarget: boolean }) {
    if (hop.ip === null) {
        return (
            <span className="flex items-center gap-2">
                <span className="animate-pulse font-mono text-white/25">* * *</span>
                <span className="text-[11px] text-white/25">no response</span>
            </span>
        );
    }
    return (
        // max-w keeps a long PTR name from stretching the table past the modal - a table
        // cell won't clip on its own, the flex box has to.
        <span className="flex min-w-0 max-w-[18rem] items-center gap-2">
            <span className="min-w-0">
                {hop.ptr ? (
                    <>
                        <span className="block truncate text-white/85">{hop.ptr}</span>
                        <span className="block truncate font-mono text-[11px] text-white/35">{hop.ip}</span>
                    </>
                ) : (
                    <span className="block truncate font-mono text-white/80">{hop.ip}</span>
                )}
            </span>
            {isTarget && (
                <span className="shrink-0 rounded-full bg-emerald-400/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300 ring-1 ring-emerald-400/20">
                    target
                </span>
            )}
        </span>
    );
}

function HopTable({ run }: { run: TraceRun }) {
    // Bar scale: the slowest average in the table. Guarded so an all-timeout trace
    // doesn't divide by zero.
    const maxAvg = run.hops.reduce((m, h) => Math.max(m, h.avg_ms ?? 0), 0) || 1;

    return (
        // One scroll container for both axes - the table is wider than a phone, and the
        // sticky header only works against the box that actually scrolls.
        <div className="max-h-[62vh] overflow-auto rounded-xl ring-1 ring-white/[0.06]">
            <table className="w-full min-w-[44rem] text-left text-sm">
                <thead className="sticky top-0 z-10 bg-[#15151b] text-[10px] uppercase tracking-[0.16em] text-white/35">
                    <tr>
                        <th className="w-10 whitespace-nowrap px-3 py-2 text-right font-medium">#</th>
                        <th className="whitespace-nowrap px-3 py-2 font-medium">Host</th>
                        <th className={th}>Loss%</th>
                        <th className={th}>Snt</th>
                        <th className={th}>Last</th>
                        <th className={th}>Avg</th>
                        <th className={th}>Best</th>
                        <th className={th}>Wrst</th>
                        <th className={th}>StDev</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-white/[0.04]">
                    {run.hops.map((hop) => {
                        const isTarget = hop.ip !== null && hop.ip === run.target;
                        const ratio = (hop.avg_ms ?? 0) / maxAvg;
                        return (
                            <tr
                                key={hop.ttl}
                                className={`transition-colors duration-200 hover:bg-white/[0.03] ${
                                    isTarget ? 'bg-emerald-400/[0.04]' : ''
                                }`}
                            >
                                <td className="whitespace-nowrap px-3 py-1.5 text-right font-mono tabular-nums text-white/30">
                                    {hop.ttl}
                                </td>
                                <td className="px-3 py-1.5">
                                    <HostCell hop={hop} isTarget={isTarget} />
                                </td>
                                <td className={`${td} ${lossClass(hop)}`}>{hop.loss_pct.toFixed(hop.loss_pct % 1 === 0 ? 0 : 1)}</td>
                                <td className={`${td} text-white/45`}>{hop.sent}</td>
                                <td className={`${td} text-white/70`}>{fmtMs(hop.last_ms)}</td>
                                {/* Avg carries the bar: it's the number an operator scans down the column. */}
                                <td className={`relative ${td} text-white/90`}>
                                    <span
                                        aria-hidden
                                        className={`absolute inset-y-[3px] left-0 rounded-sm ${barClass(ratio)}`}
                                        style={{ width: `${Math.max(hop.avg_ms === null ? 0 : 4, ratio * 100)}%` }}
                                    />
                                    <span className="relative">{fmtMs(hop.avg_ms)}</span>
                                </td>
                                <td className={`${td} text-white/45`}>{fmtMs(hop.best_ms)}</td>
                                <td className={`${td} text-white/45`}>{fmtMs(hop.worst_ms)}</td>
                                <td className={`${td} text-white/45`}>{fmtMs(hop.stdev_ms)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Live MTR-style path trace from the MyMate server to a device's mgmt IP. Starts a run on
 * mount and polls its snapshot once a second; closing the modal stops the run server-side
 * so an abandoned trace doesn't hold the single trace worker for another minute.
 */
export function TraceModal({
    deviceId,
    deviceName,
    targetIp,
    onClose,
}: {
    deviceId: number;
    deviceName: string;
    targetIp: string;
    onClose: () => void;
}) {
    const [rounds, setRounds] = useState(DEFAULT_ROUNDS);
    const [runId, setRunId] = useState<string | null>(null);
    const start = useStartTrace();
    const { data: run, error: pollError } = useTraceRun(deviceId, runId);

    // "In flight" spans the whole gap from pressing the button to the final snapshot:
    // the POST, the wait for the first poll, and the run itself. Without the middle case
    // the controls would flicker back to "Run again" for a second right after starting.
    const awaitingFirstPoll = runId !== null && run === undefined && pollError === null;
    const running = start.isPending || awaitingFirstPoll || run?.status === 'running';

    // The unmount cleanup needs the *current* run, but must not re-run on every poll -
    // a ref keeps the effect's dependency list empty while still seeing the latest ids.
    const activeRef = useRef<{ runId: string; running: boolean } | null>(null);
    useEffect(() => {
        activeRef.current =
            runId === null ? null : { runId, running: run === undefined || run.status === 'running' };
    }, [runId, run]);

    // Set once the modal unmounts, so a start whose response lands *after* the close can still
    // stop its run instead of orphaning an mtr that nothing will ever cancel.
    const unmountedRef = useRef(false);

    const startMutate = start.mutate;
    const startRun = useCallback(
        (nextRounds: number): void => {
            const previous = activeRef.current;
            if (previous?.running) stopTrace(deviceId, previous.runId); // never leave two runs queued
            setRunId(null);
            startMutate(
                { deviceId, rounds: nextRounds },
                {
                    onSuccess: (started) => {
                        setRunId(started.run_id);
                        // Modal was closed during the start round-trip: stop the run we just created.
                        if (unmountedRef.current) stopTrace(deviceId, started.run_id);
                    },
                },
            );
        },
        [deviceId, startMutate],
    );

    function stopNow(): void {
        if (runId !== null) stopTrace(deviceId, runId);
    }

    // Start as soon as the modal opens - the operator asked for a trace by opening it.
    // The ref guard keeps StrictMode's double-invoked mount effect from queueing two runs.
    const startedRef = useRef(false);
    useEffect(() => {
        if (startedRef.current) return;
        startedRef.current = true;
        startRun(DEFAULT_ROUNDS);
    }, [startRun]);

    // Stop the server-side run when the modal goes away mid-trace. Flag the unmount so a start
    // still in flight (runId not set yet) gets stopped from its own onSuccess above.
    useEffect(
        () => () => {
            unmountedRef.current = true;
            const active = activeRef.current;
            if (active?.running) stopTrace(deviceId, active.runId);
        },
        [deviceId],
    );

    // Esc closes, matching the app's other dialogs.
    useEffect(() => {
        function onKey(e: KeyboardEvent): void {
            if (e.key === 'Escape') onClose();
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const target = run?.target ?? targetIp;
    const total = run?.rounds_total ?? rounds;
    const failure = run?.status === 'failed' ? run.error ?? 'The trace failed.' : null;
    const startFailed = start.isError;

    const statusLine =
        startFailed
            ? 'Couldn\'t start the trace'
            : run?.status === 'done'
              ? `Complete - ${run.rounds_done} rounds, ${run.hops.length} hops`
              : run?.status === 'stopped'
                ? `Stopped after ${run.rounds_done} rounds`
                : run?.status === 'failed'
                  ? 'Failed'
                  : run
                    ? `Probing - round ${run.rounds_done} of ${total}`
                    : 'Starting mtr...';

    // Portalled to <body> like the chart modal: the inspector panel is transformed, which
    // would otherwise clip a fixed-position child.
    return createPortal(
        <div className="fixed inset-0 z-[60] grid place-items-center p-4 sm:p-8">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />

            <div className="animate-rise relative w-full max-w-4xl rounded-[1.5rem] bg-white/[0.05] p-1 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] ring-1 ring-white/10">
                <div className="relative flex flex-col gap-4 rounded-[calc(1.5rem-0.25rem)] bg-[#0d0d11] p-6 ring-1 ring-white/10">
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="absolute right-4 top-4 z-10 rounded-lg p-1 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-5 w-5" />
                    </button>

                    <header className="flex flex-wrap items-start justify-between gap-3 pr-10">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                                <Path weight="light" className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="truncate text-base font-bold tracking-tight text-white">Trace</h2>
                                <p className="truncate text-xs text-white/40">
                                    {deviceName} <span className="text-white/25">-</span>{' '}
                                    <span className="font-mono text-white/55">{target}</span>
                                </p>
                            </div>
                        </div>
                        <span className="flex items-center gap-1.5 font-mono text-xs tabular-nums text-white/45">
                            {running && <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin text-emerald-300" />}
                            {run ? run.rounds_done : 0}/{total}
                        </span>
                    </header>

                    {(failure !== null || startFailed) && (
                        <div className="flex items-start gap-2 rounded-xl bg-rose-500/10 px-3 py-2.5 text-xs text-rose-200 ring-1 ring-rose-400/20">
                            <WarningCircle weight="light" className="mt-px h-4 w-4 shrink-0" />
                            <span className="min-w-0 break-words">
                                {startFailed ? 'Couldn\'t start the trace - the server refused the request.' : failure}
                            </span>
                        </div>
                    )}

                    {pollError !== null && !startFailed && (
                        <div className="flex items-start gap-2 rounded-xl bg-white/[0.03] px-3 py-2.5 text-xs text-white/50 ring-1 ring-white/[0.06]">
                            <WarningCircle weight="light" className="mt-px h-4 w-4 shrink-0" />
                            This trace is no longer available - run it again for a fresh path.
                        </div>
                    )}

                    {run && run.hops.length > 0 ? (
                        <HopTable run={run} />
                    ) : (
                        // The first poll can land before mtr has transmitted anything - say so
                        // rather than flashing an empty table.
                        <div className="grid place-items-center rounded-xl bg-white/[0.02] py-16 text-center ring-1 ring-white/[0.06]">
                            <p className="flex items-center gap-2 text-sm text-white/40">
                                {running && <CircleNotch weight="bold" className="h-4 w-4 animate-spin" />}
                                {failure ?? (startFailed ? 'Nothing to show.' : 'Waiting for the first hop...')}
                            </p>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-[11px] tabular-nums text-white/35">{statusLine}</p>
                        <div className="flex items-center gap-2">
                            {/* Round count is fixed for the life of a run - pick it before the next one. */}
                            <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 text-[11px] ring-1 ring-white/10">
                                {ROUND_CHOICES.map((r) => (
                                    <button
                                        key={r}
                                        onClick={() => setRounds(r)}
                                        disabled={running}
                                        title={`${r} probe rounds (roughly ${r} seconds)`}
                                        className={`rounded-full px-2.5 py-0.5 tabular-nums transition-colors duration-300 disabled:opacity-40 ${
                                            rounds === r ? 'bg-white/10 text-white/90' : 'text-white/40 hover:text-white/70'
                                        }`}
                                    >
                                        {r}
                                    </button>
                                ))}
                            </div>
                            {running ? (
                                <button onClick={stopNow} className={ctrlBtn} disabled={runId === null}>
                                    <Stop weight="fill" className="h-3.5 w-3.5" /> Stop
                                </button>
                            ) : (
                                <button onClick={() => startRun(rounds)} className={ctrlBtn} disabled={start.isPending}>
                                    <ArrowClockwise weight="bold" className="h-3.5 w-3.5" /> Run again
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
