import {
    Globe, ShareNetwork, StackSimple, WifiHigh, Broadcast, HardDrives, ShieldCheck,
    VideoCamera, Database, Desktop, DeviceMobile, Printer, Cloud, Cpu, Lightning, Cube,
    type Icon,
} from '@phosphor-icons/react';

/**
 * The glyphs an operator can pin to a device (icon override). Keys are stored on the device;
 * both the map node and the inspector resolve them through here so a device looks the same in
 * both places. Null/absent icon = auto (product photo / vendor mark / device-type family icon).
 */
export const DEVICE_ICONS: Record<string, Icon> = {
    router: ShareNetwork,
    switch: StackSimple,
    ap: WifiHigh,
    dish: Broadcast,
    server: HardDrives,
    firewall: ShieldCheck,
    internet: Globe,
    cloud: Cloud,
    camera: VideoCamera,
    nvr: Database,
    desktop: Desktop,
    phone: DeviceMobile,
    printer: Printer,
    cpu: Cpu,
    power: Lightning,
    generic: Cube,
};

export const DEVICE_ICON_KEYS = Object.keys(DEVICE_ICONS);

/** The Phosphor component for a stored icon key, or null when unset/unknown (use auto glyph). */
export function deviceIcon(key: string | null | undefined): Icon | null {
    return key ? DEVICE_ICONS[key] ?? null : null;
}

/** A palette of preset colours for the icon-colour picker (plus any custom hex). */
export const ICON_COLORS = [
    '#34d399', '#38bdf8', '#a78bfa', '#f472b6', '#fbbf24', '#fb923c',
    '#f87171', '#22d3ee', '#4ade80', '#e879f9', '#94a3b8', '#ffffff',
];
