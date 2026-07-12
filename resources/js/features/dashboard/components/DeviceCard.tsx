import { DeviceTypeBadge } from '../../../components/DeviceTypeBadge';
import { StatusDot } from '../../../components/StatusDot';
import type { Device, DeviceStatus } from '../../../types';

const STATUS_LABEL: Record<DeviceStatus, string> = { up: 'Operational', down: 'Down', unknown: 'Unknown' };
const STATUS_TEXT: Record<DeviceStatus, string> = {
    up: 'text-emerald-300',
    down: 'text-rose-300',
    unknown: 'text-amber-300/90',
};
// Outage illumination (FR-32): a down card gets a strong red ring + pulsing glow so it
// stands out across a room; unknown gets a milder amber ring; up is calm. Never colour
// alone - the status word + dot are always shown.
const CARD_TONE: Record<DeviceStatus, string> = {
    down: 'ring-2 ring-rose-500/70 animate-cardpulse bg-rose-500/[0.06]',
    unknown: 'ring-1 ring-amber-400/25',
    up: 'ring-1 ring-white/10',
};

/** A big, glanceable device card for the dashboard / wallboard. */
export function DeviceCard({ device }: { device: Device }) {
    const status = device.status;

    return (
        <div className={`flex h-full flex-col justify-between gap-3 rounded-2xl bg-white/[0.03] p-4 transition-all duration-500 ease-fluid ${CARD_TONE[status] ?? CARD_TONE.unknown}`}>
            <div className="flex items-start justify-between gap-2">
                <DeviceTypeBadge type={device.device_type} className="h-7 px-2" />
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <StatusDot status={status} />
                    <span className={STATUS_TEXT[status] ?? STATUS_TEXT.unknown}>{STATUS_LABEL[status] ?? 'Unknown'}</span>
                </span>
            </div>
            <div className="min-w-0">
                <h3 className="truncate text-lg font-bold leading-tight tracking-tight text-white" title={device.name}>
                    {device.name}
                </h3>
                <p className="mt-1 truncate font-mono text-sm text-white/45">{device.mgmt_ip}</p>
            </div>
        </div>
    );
}
