import { useEffect, useState } from 'react';
import { ChartLine, Plus, PencilSimple, Trash } from '@phosphor-icons/react';
import { useGraphs, useDeleteGraph, useGraphData } from '../api/graphs';
import { GraphChart } from './GraphChart';
import { GraphEditor } from './GraphEditor';
import { useIsAdmin } from '../../auth/api/auth';
import { pushToast } from '../../../lib/toast';
import type { Graph } from '../../../types';

const RANGES: { key: string; label: string }[] = [
    { key: '1h', label: '1h' },
    { key: '6h', label: '6h' },
    { key: '24h', label: '24h' },
    { key: '7d', label: '7d' },
    { key: '30d', label: '30d' },
];

const pill = (active: boolean) =>
    `rounded-full px-2.5 py-1 text-xs font-medium transition-colors ${active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'}`;

export function GraphsView() {
    const isAdmin = useIsAdmin();
    const { data: graphs } = useGraphs();
    const del = useDeleteGraph();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [range, setRange] = useState('24h');
    const [editing, setEditing] = useState<Graph | 'new' | null>(null);

    // Default to the first graph once they load.
    useEffect(() => {
        if (selectedId === null && graphs && graphs.length > 0) setSelectedId(graphs[0].id);
    }, [graphs, selectedId]);

    const selected = graphs?.find((g) => g.id === selectedId) ?? null;
    const { data: graphData, isLoading } = useGraphData(editing ? null : selectedId, range);

    if (editing) {
        return (
            <div className="h-full overflow-y-auto p-6 lg:p-8">
                <div className="animate-rise">
                    <h1 className="mb-4 px-1 text-lg font-bold tracking-tight text-white">{editing === 'new' ? 'New graph' : 'Edit graph'}</h1>
                    <GraphEditor
                        initial={editing === 'new' ? undefined : editing}
                        onDone={() => setEditing(null)}
                        onCancel={() => setEditing(null)}
                    />
                </div>
            </div>
        );
    }

    return (
        <div className="flex h-full flex-col overflow-hidden p-6 lg:p-8">
            <header className="mb-6 flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-400/20">
                        <ChartLine weight="light" className="h-5 w-5" />
                    </span>
                    <div>
                        <h1 className="text-lg font-bold tracking-tight text-white">Graphs</h1>
                        <p className="text-xs text-white/40">Custom charts of any number of interfaces</p>
                    </div>
                </div>
                {isAdmin && (
                    <button onClick={() => setEditing('new')} className="flex items-center gap-1.5 rounded-full bg-emerald-500 px-3.5 py-1.5 text-sm font-semibold text-emerald-950 hover:bg-emerald-400">
                        <Plus weight="bold" className="h-4 w-4" /> New graph
                    </button>
                )}
            </header>

            <div className="flex min-h-0 flex-1 gap-6">
                {/* Saved graphs list */}
                <aside className="w-56 shrink-0 space-y-1 overflow-y-auto">
                    {(graphs ?? []).length === 0 && <p className="px-2 text-xs text-white/35">No graphs yet.</p>}
                    {(graphs ?? []).map((g) => (
                        <button
                            key={g.id}
                            onClick={() => setSelectedId(g.id)}
                            className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors ${g.id === selectedId ? 'bg-white/[0.06] text-white ring-1 ring-white/10' : 'text-white/60 hover:bg-white/[0.03] hover:text-white/85'}`}
                        >
                            <ChartLine weight="light" className="h-4 w-4 shrink-0 text-emerald-300/70" />
                            <span className="min-w-0 flex-1 truncate">{g.name}</span>
                        </button>
                    ))}
                </aside>

                {/* Selected graph */}
                <main className="min-w-0 flex-1 overflow-y-auto">
                    {selected ? (
                        <div className="animate-rise rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h2 className="truncate text-base font-semibold text-white/90">{selected.name}</h2>
                                <div className="flex items-center gap-2">
                                    <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                                        {RANGES.map((r) => (
                                            <button key={r.key} onClick={() => setRange(r.key)} className={pill(range === r.key)}>{r.label}</button>
                                        ))}
                                    </div>
                                    {isAdmin && (
                                        <>
                                            <button onClick={() => setEditing(selected)} title="Edit" className="rounded-lg p-1.5 text-white/45 hover:bg-white/5 hover:text-white/85"><PencilSimple weight="bold" className="h-4 w-4" /></button>
                                            <button
                                                onClick={() => del.mutate(selected.id, { onSuccess: () => { setSelectedId(null); pushToast({ title: 'Graph deleted', tone: 'info' }); } })}
                                                title="Delete" className="rounded-lg p-1.5 text-white/45 hover:bg-rose-500/10 hover:text-rose-300"><Trash weight="bold" className="h-4 w-4" /></button>
                                        </>
                                    )}
                                </div>
                            </div>
                            {isLoading ? (
                                <div className="grid h-[260px] place-items-center text-sm text-white/40">Loading...</div>
                            ) : graphData ? (
                                <GraphChart data={graphData} />
                            ) : null}
                        </div>
                    ) : (
                        <div className="grid h-full place-items-center text-center">
                            <div className="max-w-xs">
                                <p className="text-sm font-medium text-white/70">No graph selected</p>
                                <p className="mt-1 text-xs text-white/40">{isAdmin ? 'Create a graph to plot any interfaces together, with an optional combined total.' : 'No graphs have been created yet.'}</p>
                            </div>
                        </div>
                    )}
                </main>
            </div>
        </div>
    );
}
