import { useEffect, useMemo, useState } from 'react';
import { setQuery } from './location';

// The graph range, shared by every graph on a tab and kept in the URL:
//   ?range=24h                 a preset, always ending now (and refreshing)
//   ?from=<unix>&to=<unix>     a fixed window (custom pick or a drag-zoom)
//   &cmp=1                     overlay the previous period of the same length

export const PRESETS: [string, number][] = [
    ['1h', 3600],
    ['6h', 21_600],
    ['24h', 86_400],
    ['7d', 604_800],
    ['30d', 2_592_000],
    ['90d', 7_776_000],
    ['180d', 15_552_000],
    ['1y', 31_536_000],
];

export const DEFAULT_PRESET = '24h';

export interface GraphRange {
    from: number; // unix seconds
    to: number;
    preset: string | null; // null = a fixed window
    live: boolean; // ends now, so it keeps refreshing
}

// Zoom history for the Back button. Kept in memory rather than browser history, so the
// browser's own Back still leaves the page in one step.
let backStack: string[] = [];

function currentRangeQuery(params: URLSearchParams): string {
    return JSON.stringify({ range: params.get('range'), from: params.get('from'), to: params.get('to') });
}

function presetSeconds(p: string | null): number | null {
    return PRESETS.find(([k]) => k === p)?.[1] ?? null;
}

/** Floor to the minute so a live range's query key only changes once a minute. */
const nowFloor = () => Math.floor(Date.now() / 60_000) * 60;

export function useGraphRange(params: URLSearchParams) {
    const [now, setNow] = useState(nowFloor);
    const rangeParam = params.get('range');
    const fromParam = params.get('from');
    const toParam = params.get('to');
    const compare = params.get('cmp') === '1';

    const range = useMemo<GraphRange>(() => {
        const from = Number(fromParam);
        const to = Number(toParam);
        if (fromParam && toParam && Number.isFinite(from) && Number.isFinite(to) && to > from) {
            return { from, to, preset: null, live: false };
        }
        const preset = presetSeconds(rangeParam) ? (rangeParam as string) : DEFAULT_PRESET;
        return { from: now - (presetSeconds(preset) ?? 86_400), to: now, preset, live: true };
    }, [rangeParam, fromParam, toParam, now]);

    // live presets slide forward once a minute
    useEffect(() => {
        if (!range.live) return;
        const t = window.setInterval(() => setNow(nowFloor()), 60_000);
        return () => window.clearInterval(t);
    }, [range.live]);

    function remember() {
        backStack = [...backStack.slice(-30), currentRangeQuery(params)];
    }

    return {
        range,
        compare,
        canGoBack: backStack.length > 0,
        setPreset(preset: string) {
            remember();
            setNow(nowFloor());
            setQuery({ range: preset === DEFAULT_PRESET ? null : preset, from: null, to: null });
        },
        setWindow(from: number, to: number) {
            if (to - from < 60) return; // a stray click, not a zoom
            remember();
            setQuery({ range: null, from: String(Math.round(from)), to: String(Math.round(to)) });
        },
        back() {
            const prev = backStack.pop();
            if (!prev) return;
            const q = JSON.parse(prev) as { range: string | null; from: string | null; to: string | null };
            setQuery(q);
        },
        reset() {
            backStack = [];
            setQuery({ range: null, from: null, to: null });
        },
        setCompare(on: boolean) {
            setQuery({ cmp: on ? '1' : null });
        },
    };
}

export type GraphRangeControl = ReturnType<typeof useGraphRange>;

/** Forget the zoom history, eg when moving to another device. */
export function clearRangeHistory(): void {
    backStack = [];
}
