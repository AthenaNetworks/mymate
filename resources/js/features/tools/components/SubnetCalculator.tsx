import { useMemo, useState } from 'react';
import { ToolCard, inputCls } from './shared';

interface Row {
    label: string;
    value: string;
}

// ---- IPv4 ----

function v4ToInt(ip: string): number | null {
    const parts = ip.split('.');
    if (parts.length !== 4) return null;
    let n = 0;
    for (const p of parts) {
        if (!/^\d{1,3}$/.test(p)) return null;
        const o = Number(p);
        if (o > 255) return null;
        n = (n << 8) | o;
    }
    return n >>> 0;
}

const v4ToStr = (n: number): string => [24, 16, 8, 0].map((s) => (n >>> s) & 0xff).join('.');

function calcV4(ip: string, prefix: number): Row[] | null {
    const int = v4ToInt(ip);
    if (int === null || prefix < 0 || prefix > 32) return null;

    const mask = prefix === 0 ? 0 : (0xffffffff << (32 - prefix)) >>> 0;
    const network = (int & mask) >>> 0;
    const broadcast = (network | (~mask >>> 0)) >>> 0;
    const total = 2 ** (32 - prefix);

    const usable = prefix >= 31 ? total : total - 2;
    const firstHost = prefix >= 31 ? network : (network + 1) >>> 0;
    const lastHost = prefix >= 31 ? broadcast : (broadcast - 1) >>> 0;

    return [
        { label: 'Network', value: `${v4ToStr(network)}/${prefix}` },
        { label: 'Netmask', value: v4ToStr(mask) },
        { label: 'Wildcard', value: v4ToStr(~mask >>> 0) },
        { label: 'Broadcast', value: prefix >= 31 ? '-' : v4ToStr(broadcast) },
        { label: 'First host', value: v4ToStr(firstHost) },
        { label: 'Last host', value: v4ToStr(lastHost) },
        { label: 'Usable hosts', value: usable.toLocaleString() },
        { label: 'Total addresses', value: total.toLocaleString() },
    ];
}

// ---- IPv6 ----

function v6ToBig(ip: string): bigint | null {
    if (ip.includes('.')) return null; // embedded-v4 form not handled here, keep it simple
    let head: string[];
    let tail: string[];
    if (ip.includes('::')) {
        const [l, r] = ip.split('::');
        if (ip.indexOf('::') !== ip.lastIndexOf('::')) return null; // only one '::' allowed
        head = l === '' ? [] : l.split(':');
        tail = r === '' ? [] : r.split(':');
        const fill = 8 - head.length - tail.length;
        if (fill < 0) return null;
        head = [...head, ...Array(fill).fill('0'), ...tail];
    } else {
        head = ip.split(':');
        if (head.length !== 8) return null;
    }
    let n = 0n;
    for (const g of head) {
        if (!/^[0-9a-fA-F]{1,4}$/.test(g)) return null;
        n = (n << 16n) | BigInt(parseInt(g, 16));
    }
    return n;
}

function bigToV6(n: bigint): string {
    const groups: string[] = [];
    for (let i = 7; i >= 0; i--) groups.push(((n >> BigInt(i * 16)) & 0xffffn).toString(16));

    // Compress the longest run of zero groups to '::'.
    let bestStart = -1;
    let bestLen = 0;
    let curStart = -1;
    let curLen = 0;
    groups.forEach((g, i) => {
        if (g === '0') {
            if (curStart === -1) curStart = i;
            curLen++;
            if (curLen > bestLen) {
                bestLen = curLen;
                bestStart = curStart;
            }
        } else {
            curStart = -1;
            curLen = 0;
        }
    });

    if (bestLen < 2) return groups.join(':');
    const before = groups.slice(0, bestStart).join(':');
    const after = groups.slice(bestStart + bestLen).join(':');
    return `${before}::${after}`;
}

function calcV6(ip: string, prefix: number): Row[] | null {
    const int = v6ToBig(ip);
    if (int === null || prefix < 0 || prefix > 128) return null;

    const full = (1n << 128n) - 1n;
    const mask = prefix === 0 ? 0n : (full ^ ((1n << BigInt(128 - prefix)) - 1n)) & full;
    const network = int & mask;
    const last = network | (full ^ mask);
    const total = 1n << BigInt(128 - prefix);

    return [
        { label: 'Network', value: `${bigToV6(network)}/${prefix}` },
        { label: 'First address', value: bigToV6(network) },
        { label: 'Last address', value: bigToV6(last) },
        { label: 'Prefix', value: `/${prefix}` },
        { label: 'Total addresses', value: total > 10n ** 15n ? `2^${128 - prefix}` : total.toLocaleString() },
    ];
}

function compute(input: string): { rows: Row[]; family: 4 | 6 } | null {
    const value = input.trim();
    if (!value.includes('/')) return null;
    const [addr, prefixStr] = value.split('/');
    if (!/^\d{1,3}$/.test(prefixStr)) return null;
    const prefix = Number(prefixStr);

    if (addr.includes(':')) {
        const rows = calcV6(addr, prefix);
        return rows ? { rows, family: 6 } : null;
    }
    const rows = calcV4(addr, prefix);
    return rows ? { rows, family: 4 } : null;
}

/** IPv4/IPv6 subnet calculator - all client-side, updates as you type. */
export function SubnetCalculator() {
    const [input, setInput] = useState('');
    const result = useMemo(() => compute(input), [input]);
    const touched = input.trim() !== '';

    return (
        <ToolCard title="Subnet calculator" description="IPv4 and IPv6 subnet maths, computed in the browser as you type.">
            <div className="max-w-md">
                <label className="mb-1 block text-[10px] uppercase tracking-[0.16em] text-white/35">Address / prefix</label>
                <input
                    className={inputCls}
                    placeholder="192.168.10.0/24  or  2001:db8::/48"
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    spellCheck={false}
                    autoComplete="off"
                />
            </div>

            {result ? (
                <>
                    <div className="mt-3 inline-flex rounded-full bg-white/[0.04] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-white/50 ring-1 ring-white/10">
                        IPv{result.family}
                    </div>
                    <dl className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {result.rows.map((row) => (
                            <div key={row.label} className="rounded-xl bg-white/[0.02] px-3 py-2.5 ring-1 ring-white/[0.06]">
                                <dt className="text-[10px] uppercase tracking-[0.16em] text-white/35">{row.label}</dt>
                                <dd className="mt-0.5 break-all font-mono text-sm text-white/85">{row.value}</dd>
                            </div>
                        ))}
                    </dl>
                </>
            ) : (
                touched && <p className="mt-3 text-xs text-white/40">Enter an address with a prefix, e.g. 10.0.0.0/8 or fd00::/64.</p>
            )}
        </ToolCard>
    );
}
