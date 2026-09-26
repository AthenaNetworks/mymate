import { useMemo, useState } from 'react';
import { ArrowLeft } from '@phosphor-icons/react';
import { useHistoryCatalog, type BillingResult } from '../api/devicePage';
import { useDeviceInterfaces } from '../../topology/api/getDeviceInterfaces';
import { formatRate } from '../../../lib/formatRate';
import { portSections } from '../lib/specs';
import { useGraphRange } from '../lib/range';
import { openDevicePage } from '../lib/location';
import { HistoryGraph } from './HistoryGraph';
import { RangeBar } from './RangeBar';
import { BillingSection } from './BillingSection';
import type { ChartRefLine } from './TimeChart';
import { Card, Detail, Empty } from './ui';
import type { Device } from '../../../types';

const BILL_IN = '#e0a526';
const BILL_OUT = '#d9689a';

/** One port: its graphs (traffic, util, errors, optical... whatever is recorded) and billing. */
export function PortPage({ device, portId, params }: { device: Device; portId: number; params: URLSearchParams }) {
    const { data: ifaces, isLoading } = useDeviceInterfaces(device.id);
    const { data: catalog } = useHistoryCatalog(device.id);
    const control = useGraphRange(params);
    const [billing, setBilling] = useState<BillingResult | null>(null);
    const [billOnGraph, setBillOnGraph] = useState(true);
    const iface = ifaces?.find((i) => i.id === portId) ?? null;

    const sections = useMemo(() => (catalog && iface ? portSections(catalog, String(portId), iface.name) : []), [catalog, iface, portId]);

    // the billing period's 95th percentile as reference lines on the traffic graph
    const billRefs = useMemo<ChartRefLine[]>(() => {
        const mine = billing?.ports.find((p) => p.key === String(portId));
        if (!billOnGraph || !mine) return [];
        const out: ChartRefLine[] = [];
        if (mine.p95_in !== null) out.push({ id: 'bill-in', label: 'bill 95th in', value: mine.p95_in, color: BILL_IN });
        if (mine.p95_out !== null) out.push({ id: 'bill-out', label: 'bill 95th out', value: mine.p95_out, color: BILL_OUT, mirrored: true });
        return out;
    }, [billing, billOnGraph, portId]);

    const back = () => {
        const q = new URLSearchParams(params);
        q.set('tab', 'ports');
        openDevicePage(device.id, null, q);
    };

    if (isLoading) return <p className="text-sm text-white/35">Loading...</p>;
    if (!iface) return <Empty>That port isn't on this device.</Empty>;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <button type="button" onClick={back} className="flex items-center gap-1.5 text-xs text-white/40 transition-colors hover:text-white/80">
                    <ArrowLeft weight="bold" className="h-3.5 w-3.5" /> Ports
                </button>
                <h2 className="text-lg font-bold tracking-tight text-white">{iface.name}</h2>
                {iface.description && <span className="text-sm text-white/45">{iface.description}</span>}
            </div>

            <Card>
                <div className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4 lg:grid-cols-7">
                    <Detail label="Status" value={iface.oper_status ?? '-'} />
                    <Detail label="Speed" value={iface.speed_mbps ? formatRate(iface.speed_mbps * 1e6) : '-'} mono />
                    <Detail label="In now" value={formatRate(iface.bps_in)} mono />
                    <Detail label="Out now" value={formatRate(iface.bps_out)} mono />
                    <Detail label="Util in / out" value={iface.util_in === null && iface.util_out === null ? '-' : `${(iface.util_in ?? 0).toFixed(1)}% / ${(iface.util_out ?? 0).toFixed(1)}%`} mono />
                    <Detail label="ifIndex" value={String(iface.if_index)} mono />
                    <Detail
                        label="Optical Rx / Tx"
                        value={iface.optical_rx_dbm === null && iface.optical_tx_dbm === null ? '-' : `${iface.optical_rx_dbm?.toFixed(2) ?? '-'} / ${iface.optical_tx_dbm?.toFixed(2) ?? '-'} dBm`}
                        mono
                    />
                </div>
            </Card>

            <div className="sticky top-0 z-20 -mx-4 bg-surface-deep/90 px-4 py-2 backdrop-blur-xl sm:-mx-6 sm:px-6">
                <RangeBar control={control} maxDays={catalog?.max_days} />
            </div>

            {sections.length === 0 ? (
                <Empty>No history recorded for this port yet.</Empty>
            ) : (
                sections.map((sec) => (
                    <section key={sec.group} className="space-y-3">
                        <h3 className="text-[11px] font-medium uppercase tracking-[0.2em] text-white/35">{sec.title}</h3>
                        <div className="grid gap-3">
                            {sec.specs.map((spec) => (
                                <HistoryGraph
                                    key={spec.id}
                                    deviceId={device.id}
                                    spec={spec}
                                    range={control.range}
                                    compare={control.compare}
                                    onZoom={(a, b) => control.setWindow(a, b)}
                                    extraRefs={spec.unit === 'bps' ? billRefs : undefined}
                                    height={spec.unit === 'bps' ? 240 : 170}
                                    fileBase={`${device.name}-${iface.name}`}
                                />
                            ))}
                        </div>
                    </section>
                ))
            )}

            <BillingSection
                device={device}
                portId={portId}
                ifaces={ifaces ?? []}
                onResult={setBilling}
                onGraph={billOnGraph}
                setOnGraph={setBillOnGraph}
                onShowPeriod={(from, to) => control.setWindow(from, to)}
            />
        </div>
    );
}
