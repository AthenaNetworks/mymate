import { useEffect, useState } from 'react';
import { Globe, WifiHigh, Broadcast, HardDrives, ShareNetwork, StackSimple, Cube, type Icon } from '@phosphor-icons/react';
import type { DeviceType } from '../../../types';
import { deviceIcon } from '../../../components/deviceIcons';
import { isWall, wallToken } from '../../../lib/wall';

// Product photos come from the authenticated device endpoint normally, or the token-gated public
// one when running as a shared wallboard (GitHub #15) - so photos still render with no session.
function iconSrc(deviceId: number, attempt: number): string {
    const q = attempt > 0 ? `?r=${attempt}` : '';
    return isWall()
        ? `/api/public/wall/${wallToken()}/devices/${deviceId}/icon${q}`
        : `/api/devices/${deviceId}/icon${q}`;
}

/**
 * Which drawn family icon represents a device. The coarse `device_type` is refined by MikroTik
 * model families (a SXT/LHG/DISH is a dish/CPE, not a generic AP). Used as the fallback for
 * every device, and shown for a MikroTik until its product photo has been fetched + cached.
 */
function familyIcon(type: DeviceType, model: string): Icon {
    const m = model.toLowerCase();
    if (/\b(sxt|lhg|dynadish|dish|qrt|disc|ldf|mant|ptp)\b/.test(m)) return Broadcast; // dish / point-to-point
    switch (type) {
        case 'internet':
            return Globe;
        case 'router':
            return ShareNetwork;
        case 'switch':
            return StackSimple;
        case 'ap':
            return WifiHigh;
        case 'server':
            return HardDrives;
        default:
            return Cube;
    }
}

/**
 * Brand-coloured monogram per hardware vendor. Drawn (not a shipped brand asset) so it stays
 * self-contained and licence-clean, crisp at any zoom, and works in either theme. Substring
 * match so "MikroTikls SIA" / "Ubiquiti Networks" etc. still resolve. WISP-common vendors first.
 */
const VENDOR_MARKS: { key: string; short: string; bg: string }[] = [
    { key: 'mikrotik', short: 'MT', bg: '#E4002B' },
    { key: 'ubiquiti', short: 'UI', bg: '#0559C9' },
    { key: 'ubnt', short: 'UI', bg: '#0559C9' },
    { key: 'cambium', short: 'CN', bg: '#00954C' },
    { key: 'cisco', short: 'CI', bg: '#049FD9' },
    { key: 'juniper', short: 'JN', bg: '#84B135' },
    { key: 'mimosa', short: 'MI', bg: '#F26722' },
    { key: 'aruba', short: 'AR', bg: '#FF8300' },
    { key: 'ruckus', short: 'RK', bg: '#CE0E2D' },
    { key: 'netgear', short: 'NG', bg: '#00447C' },
    { key: 'tp-link', short: 'TP', bg: '#4ACBD6' },
    { key: 'tplink', short: 'TP', bg: '#4ACBD6' },
    { key: 'dell', short: 'DL', bg: '#0076CE' },
    { key: 'hpe', short: 'HP', bg: '#01A982' },
    { key: 'hewlett', short: 'HP', bg: '#01A982' },
];

function vendorMark(vendor: string | null): { short: string; bg: string } | null {
    const v = (vendor ?? '').toLowerCase();
    if (v === '') return null;
    return VENDOR_MARKS.find((m) => v.includes(m.key)) ?? null;
}

/**
 * A device's map glyph, best available first:
 *   1. the real MikroTik product photo (served from our cache) when the model resolves,
 *   2. a brand-coloured vendor monogram when we know the vendor but not the exact model,
 *   3. the drawn device-family icon otherwise.
 */
export function DeviceGlyph({
    deviceId,
    vendor,
    model,
    type,
    icon = null,
    iconColor = null,
    className = 'h-5 w-5',
}: {
    deviceId: number;
    vendor: string | null;
    model: string | null;
    type: DeviceType;
    icon?: string | null; // operator override glyph key (deviceIcons); null = auto
    iconColor?: string | null; // hex; tints the custom / family icon
    className?: string;
}) {
    const [loaded, setLoaded] = useState(false);
    // Errored on the current attempt; `attempt` bumps the request (cache-busted) to re-fetch.
    const [errored, setErrored] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const MAX_RETRIES = 3;

    // First request for an uncached MikroTik photo 404s but dispatches a server-side fetch; poll
    // a few more times (backing off) so the freshly cached image appears without a page reload.
    useEffect(() => {
        if (!errored || attempt >= MAX_RETRIES) return;
        const t = setTimeout(() => {
            setErrored(false);
            setAttempt((a) => a + 1);
        }, 2000 * (attempt + 1));
        return () => clearTimeout(t);
    }, [errored, attempt]);

    // An explicit operator-chosen icon wins over the auto photo/monogram/family glyph.
    const Custom = deviceIcon(icon);
    if (Custom) {
        return <Custom weight="duotone" className={className} style={iconColor ? { color: iconColor } : undefined} />;
    }

    const isMikrotik = (vendor ?? '').toLowerCase().includes('mikrotik');
    // Only chase a product photo for a real model name. Devices whose model never resolved past
    // a raw board id (e.g. "0x0002") can't map to a MikroTik product page, so don't 404-loop on
    // them - go straight to the vendor mark / drawn icon.
    const realModel = !!model && !/^0x[0-9a-f]+$/i.test(model.trim());
    const gaveUp = errored && attempt >= MAX_RETRIES;
    const useImage = isMikrotik && realModel && !gaveUp;
    const mark = vendorMark(vendor);
    const Fallback = familyIcon(type, model ?? '');

    return (
        <>
            {useImage && (
                <img
                    src={iconSrc(deviceId, attempt)}
                    alt=""
                    onLoad={() => setLoaded(true)}
                    onError={() => setErrored(true)}
                    className={`${className} object-contain ${loaded ? '' : 'hidden'}`}
                />
            )}
            {!loaded && (
                mark ? (
                    <span
                        className={`${className} grid place-items-center rounded-[4px] font-bold leading-none text-white`}
                        style={{ background: mark.bg, fontSize: '0.5em', letterSpacing: '-0.02em' }}
                        title={vendor ?? undefined}
                    >
                        {mark.short}
                    </span>
                ) : (
                    <Fallback weight="duotone" className={className} style={iconColor ? { color: iconColor } : undefined} />
                )
            )}
        </>
    );
}
