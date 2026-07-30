import { useMutation, useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/** One row of the trace: a TTL and whatever answered at it. A hop that never replied
 *  keeps its ttl but has no address and null latency (loss_pct 100) - the classic
 *  MTR "* * *" line, which we render as a placeholder rather than dropping. */
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

export type TraceStatus = 'running' | 'done' | 'failed' | 'stopped';

/** A snapshot of a trace run. The backend rewrites this at most once a second while
 *  mtr streams, so every poll is a complete picture - no client-side accumulation. */
export interface TraceRun {
    run_id: string;
    status: TraceStatus;
    target: string;
    rounds_total: number;
    rounds_done: number;
    error: string | null;
    hops: TraceHop[];
}

/** What POST /trace hands back: the id to poll. */
export interface TraceStarted {
    run_id: string;
    status: TraceStatus;
}

// Query keys for a single trace run's snapshot.
export const traceKeys = {
    all: ['device-trace'] as const,
    run: (deviceId: number, runId: string) => [...traceKeys.all, deviceId, runId] as const,
};

/** Kick off a trace from the MyMate server to the device's mgmt IP. The target is chosen
 *  server-side from the device, so there is nothing to aim here - only how long to run. */
export function useStartTrace() {
    return useMutation({
        mutationFn: async ({ deviceId, rounds }: { deviceId: number; rounds?: number }): Promise<TraceStarted> => {
            const { data } = await apiClient.post<TraceStarted>(
                `/devices/${deviceId}/trace`,
                rounds === undefined ? {} : { rounds },
            );
            return data;
        },
    });
}

async function fetchTraceRun(deviceId: number, runId: string): Promise<TraceRun> {
    const { data } = await apiClient.get<TraceRun>(`/devices/${deviceId}/trace/${runId}`);
    return data;
}

/**
 * Poll a run's snapshot once a second while it is running, then stop dead - a finished
 * run is immutable, so continuing to poll would just burn requests (and the cached
 * snapshot expires server-side anyway). A 404 means the run expired or belongs to
 * another device; retrying that never helps, so it surfaces as an error immediately.
 */
export function useTraceRun(deviceId: number | null, runId: string | null) {
    return useQuery({
        queryKey: traceKeys.run(deviceId ?? 0, runId ?? ''),
        queryFn: () => fetchTraceRun(deviceId as number, runId as string),
        enabled: deviceId !== null && runId !== null,
        refetchInterval: (query) => (query.state.data?.status === 'running' ? 1000 : false),
        retry: false,
        gcTime: 0, // each run id is used once; don't keep dead snapshots around
    });
}

/**
 * Fire-and-forget stop, for the modal closing/unmounting mid-run: the job can otherwise
 * keep an mtr process alive for another minute or two on a queue with one worker. Errors
 * are swallowed deliberately - by the time this fires the component is already gone, and
 * a run that already finished 404s harmlessly.
 */
export function stopTrace(deviceId: number, runId: string): void {
    void apiClient.delete(`/devices/${deviceId}/trace/${runId}`).catch(() => undefined);
}
