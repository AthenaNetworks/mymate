import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export type ToolKind = 'ping' | 'trace' | 'sweep' | 'portscan';
export type RunStatus = 'running' | 'done' | 'stopped' | 'failed';

/** The uniform envelope every streaming tool run polls (see App\Services\Tools\ToolRun).
 *  `result` is the only kind-specific part - each tool narrows R to its own payload. */
export interface ToolRun<R = unknown> {
    run_id: string;
    kind: ToolKind;
    target: string;
    status: RunStatus;
    error: string | null;
    result: R;
}

interface RunStarted {
    run_id: string;
    kind: ToolKind;
    status: RunStatus;
}

// ---- Result payload shapes, one per streaming tool ----

export interface PingProbe {
    seq: number;
    ms: number | null;
}
export interface PingResult {
    sent: number;
    recv: number;
    loss_pct: number;
    last_ms: number | null;
    avg_ms: number | null;
    best_ms: number | null;
    worst_ms: number | null;
    stdev_ms: number | null;
    probes: PingProbe[];
}

export interface TraceHop {
    ttl: number;
    ip: string | null;
    ptr: string | null;
    sent: number;
    recv: number;
    loss_pct: number;
    last_ms: number | null;
    avg_ms: number | null;
    best_ms: number | null;
    worst_ms: number | null;
    stdev_ms: number | null;
}
export interface TraceResult {
    rounds_total: number;
    rounds_done: number;
    hops: TraceHop[];
}

export interface OpenPort {
    port: number;
    state?: string;
    service: string | null;
}
export interface PortScanResult {
    total: number;
    scanned: number;
    open: OpenPort[];
}

export interface SweepHost {
    ip: string;
    rdns: string | null;
    netbios: string | null;
    group: string | null;
    mac: string | null;
    ports: { port: number; service: string | null }[];
    pending: boolean;
}
export interface SweepResult {
    total: number;
    phase: string;
    alive: number;
    hosts: SweepHost[];
}

const START_PATHS: Record<ToolKind, string> = {
    ping: '/tools/ping',
    trace: '/tools/trace',
    sweep: '/tools/sweep',
    portscan: '/tools/portscan',
};

async function fetchRun<R>(runId: string): Promise<ToolRun<R>> {
    const { data } = await apiClient.get<ToolRun<R>>(`/tools/runs/${runId}`);
    return data;
}

/** Fire-and-forget stop, used both by the Stop button and the unmount/navigate-away cleanup.
 *  Errors are swallowed: a run that already finished 404s, and by unmount the component is gone. */
export function stopRun(runId: string): void {
    void apiClient.delete(`/tools/runs/${runId}`).catch(() => undefined);
}

/**
 * Drives one streaming tool: start a run, poll its snapshot once a second while it runs, stop
 * it on demand, and - the important part for a tool page - cancel the server-side run when the
 * component unmounts (tab switch or leaving the page), so an abandoned sweep/trace doesn't hold
 * the worker. Terminal snapshots stop polling on their own; a 404 (expired run) surfaces as an
 * error rather than an infinite retry.
 */
export function useToolRunner<R>(kind: ToolKind) {
    const [runId, setRunId] = useState<string | null>(null);

    const start = useMutation({
        mutationFn: async (body: Record<string, unknown>): Promise<RunStarted> => {
            const { data } = await apiClient.post<RunStarted>(START_PATHS[kind], body);
            return data;
        },
    });

    const query = useQuery({
        queryKey: ['tool-run', kind, runId ?? ''],
        queryFn: () => fetchRun<R>(runId as string),
        enabled: runId !== null,
        refetchInterval: (q) => (q.state.data?.status === 'running' ? 1000 : false),
        retry: false,
        gcTime: 0,
    });

    const run = query.data ?? null;

    // Same "in flight" span the device trace uses: the POST, the wait for the first poll, and the
    // run itself - so the button doesn't flicker back to idle for a second right after starting.
    const awaitingFirstPoll = runId !== null && query.data === undefined && query.error == null;
    const running = start.isPending || awaitingFirstPoll || run?.status === 'running';

    // A ref mirrors the live run id/running so the unmount cleanup sees the latest without the
    // effect re-subscribing on every poll.
    const activeRef = useRef<{ runId: string; running: boolean } | null>(null);
    useEffect(() => {
        activeRef.current = runId === null ? null : { runId, running: run === null || run.status === 'running' };
    }, [runId, run]);

    const unmountedRef = useRef(false);

    const startMutate = start.mutate;
    const begin = useCallback(
        (body: Record<string, unknown>): void => {
            const previous = activeRef.current;
            if (previous?.running) stopRun(previous.runId); // never leave two runs going at once
            setRunId(null);
            startMutate(body, {
                onSuccess: (started) => {
                    setRunId(started.run_id);
                    if (unmountedRef.current) stopRun(started.run_id); // closed during the round-trip
                },
            });
        },
        [startMutate],
    );

    const stop = useCallback((): void => {
        const active = activeRef.current;
        if (active?.running) stopRun(active.runId);
    }, []);

    // Cancel the run server-side when this tool unmounts mid-run (tab switch / navigating away).
    useEffect(
        () => () => {
            unmountedRef.current = true;
            const active = activeRef.current;
            if (active?.running) stopRun(active.runId);
        },
        [],
    );

    return {
        run,
        running,
        begin,
        stop,
        startFailed: start.isError,
        expired: query.error != null,
    };
}

// ---- bgp.tools lookup (synchronous, cached server-side) ----

export interface BgpResult {
    query: string;
    asn: string | null;
    name: string | null;
    prefix: string | null;
    ip: string | null;
    country: string | null;
    registry: string | null;
    allocated: string | null;
    raw: string;
}

export function useBgpLookup() {
    return useMutation({
        mutationFn: async (query: string): Promise<BgpResult> => {
            const { data } = await apiClient.post<BgpResult>('/tools/bgp', { query });
            return data;
        },
    });
}
