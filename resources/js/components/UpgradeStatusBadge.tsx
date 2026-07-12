import { CircleNotch, CheckCircle, WarningCircle } from '@phosphor-icons/react';
import { UPGRADE_IN_PROGRESS, type Device, type UpgradeStatus } from '../types';

const progressLabel: Record<string, string> = {
    queued: 'Queued...',
    checking: 'Checking...',
    downloading: 'Downloading...',
    rebooting: 'Rebooting...',
};

/**
 * Compact firmware-upgrade indicator: a spinner while it runs, ✓ with the version
 * when done/up-to-date, ✗ on failure, or just the current version when idle.
 * Renders nothing for a device with no known version and no upgrade activity.
 */
export function UpgradeStatusBadge({ device }: { device: Device }) {
    const s = device.upgrade_status as UpgradeStatus | null;

    if (s && UPGRADE_IN_PROGRESS.has(s)) {
        return (
            <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-amber-300" title={device.upgrade_message ?? 'Upgrading'}>
                <CircleNotch weight="bold" className="h-3.5 w-3.5 animate-spin" />
                {progressLabel[s] ?? 'Upgrading...'}
            </span>
        );
    }

    if (s === 'failed') {
        return (
            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-rose-400" title={device.upgrade_message ?? 'Upgrade failed'}>
                <WarningCircle weight="bold" className="h-3.5 w-3.5" /> failed
            </span>
        );
    }

    if (s === 'done' || s === 'up_to_date') {
        return (
            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-300" title={device.upgrade_message ?? undefined}>
                <CheckCircle weight="bold" className="h-3.5 w-3.5" />
                {device.os_version ? `v${device.os_version}` : 'done'}
                {device.up_to_date ? ' - latest' : ''}
            </span>
        );
    }

    if (device.os_version) {
        return <span className="font-mono text-[11px] text-white/40">v{device.os_version}</span>;
    }

    return null;
}
