import { useState } from 'react';
import { useBgpLookup } from '../api/tools';
import { ToolCard, primaryBtn, inputCls, ErrorStrip } from './shared';

/** Looks an IP or ASN up against bgp.tools (their whois interface, cached server-side).
 *  Returns the origin AS, name, covering prefix and RIR details. */
export function BgpLookup() {
    const [query, setQuery] = useState('');
    const lookup = useBgpLookup();
    const r = lookup.data;

    function onGo(): void {
        const q = query.trim();
        if (q !== '') lookup.mutate(q);
    }

    const fields: { label: string; value: string | null }[] = r
        ? [
              { label: 'AS', value: r.asn ? `AS${r.asn}` : null },
              { label: 'AS name', value: r.name },
              { label: 'BGP prefix', value: r.prefix },
              { label: 'Address', value: r.ip },
              { label: 'Country', value: r.country },
              { label: 'Registry', value: r.registry },
              { label: 'Allocated', value: r.allocated },
          ]
        : [];

    return (
        <ToolCard title="BGP lookup" description="Origin AS, name and covering prefix for an IP or ASN, via bgp.tools.">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[16rem] flex-1">
                    <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">IP or ASN</label>
                    <input
                        className={inputCls}
                        placeholder="1.1.1.1  or  AS13335"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && onGo()}
                        spellCheck={false}
                        autoComplete="off"
                    />
                </div>
                <button onClick={onGo} className={primaryBtn} disabled={lookup.isPending || query.trim() === ''}>
                    {lookup.isPending ? 'Looking up...' : 'Look up'}
                </button>
            </div>

            {lookup.isError && (
                <div className="mt-3">
                    <ErrorStrip>
                        {(lookup.error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                            'Lookup failed. Check the IP/ASN and try again.'}
                    </ErrorStrip>
                </div>
            )}

            {r && (
                <>
                    <dl className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {fields.map((f) => (
                            <div key={f.label} className="rounded-xl bg-white/[0.02] px-3 py-2.5 ring-1 ring-white/[0.06]">
                                <dt className="text-[10px] uppercase tracking-[0.16em] text-white/35">{f.label}</dt>
                                <dd className="mt-0.5 break-all font-mono text-sm text-white/85">{f.value ?? <span className="text-white/25">-</span>}</dd>
                            </div>
                        ))}
                    </dl>
                    {r.raw && (
                        <details className="mt-3 text-xs text-white/45">
                            <summary className="cursor-pointer select-none text-white/40 hover:text-white/60">Raw whois</summary>
                            <pre className="mt-2 max-h-56 overflow-auto rounded-xl bg-black/20 p-3 font-mono text-[11px] leading-relaxed text-white/60 ring-1 ring-white/[0.06]">
                                {r.raw}
                            </pre>
                        </details>
                    )}
                    <p className="mt-2 text-[11px] text-white/30">Data from bgp.tools</p>
                </>
            )}
        </ToolCard>
    );
}
