import { useState } from 'react';
import { Globe, WifiHigh, Broadcast, HardDrives, ShareNetwork, StackSimple, Cube, type Icon } from '@phosphor-icons/react';
import type { DeviceType } from '../../../types';

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
 * A device's map glyph. For MikroTik it shows the real product photo (served from our cache
 * by the device-icon endpoint), falling back to the drawn family icon until the image loads
 * (or forever if the model can't be resolved). Everything else uses the drawn family icon.
 */
export function DeviceGlyph({
    deviceId,
    vendor,
    model,
    type,
    className = 'h-5 w-5',
}: {
    deviceId: number;
    vendor: string | null;
    model: string | null;
    type: DeviceType;
    className?: string;
}) {
    const [loaded, setLoaded] = useState(false);
    const [failed, setFailed] = useState(false);
    const isMikrotik = (vendor ?? '').toLowerCase().includes('mikrotik');
    // Only chase a product photo for a real model name. Devices whose model never resolved past
    // a raw board id (e.g. "0x0002") can't map to a MikroTik product page, so don't 404-loop on
    // them - go straight to the drawn family icon.
    const realModel = !!model && !/^0x[0-9a-f]+$/i.test(model.trim());
    const useImage = isMikrotik && realModel && !failed;
    const Fallback = familyIcon(type, model ?? '');

    return (
        <>
            {useImage && (
                <img
                    src={`/api/devices/${deviceId}/icon`}
                    alt=""
                    onLoad={() => setLoaded(true)}
                    onError={() => setFailed(true)}
                    className={`${className} object-contain ${loaded ? '' : 'hidden'}`}
                />
            )}
            {!loaded && <Fallback weight="duotone" className={className} />}
        </>
    );
}
