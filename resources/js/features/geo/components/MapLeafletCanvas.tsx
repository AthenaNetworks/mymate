import { useEffect, useMemo, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { MapPin } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useLinks } from '../../topology/api/getLinks';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useMap } from '../../maps/api/maps';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { useIsAdmin } from '../../auth/api/auth';
import { useMapConfig } from '../api/geo';
import { MapSwitcher } from '../../maps/components/MapSwitcher';
import { linkColor } from '../../topology/lib/linkColor';
import { selectDevice, useActiveMapId } from '../../../lib/shellStore';
import type { Device, DeviceStatus, Link } from '../../../types';

const STATUS_COLOR: Record<DeviceStatus, string> = { up: '#34d399', down: '#f43f5e', unknown: '#52525b' };

/** Coordinate key - devices rounded to the same spot stack together. */
const coordKey = (lat: number, lng: number) => `${lat.toFixed(5)},${lng.toFixed(5)}`;

function dotHtml(color: string, ring = 3): string {
    return `<span style="display:block;width:14px;height:14px;border-radius:50%;background:${color};box-shadow:0 0 0 ${ring}px rgba(0,0,0,0.45),0 0 8px 1px ${color}99;border:2px solid #0d0d11"></span>`;
}

/** The busier of a link's two ends' utilisation (from the query data), for edge colour. */
function linkUtil(l: Link): number | null {
    const vals = [l.a_interface?.util_in, l.a_interface?.util_out, l.b_interface?.util_in, l.b_interface?.util_out].filter(
        (v): v is number => v != null,
    );
    return vals.length ? Math.max(...vals) : null;
}

/**
 * A single map rendered on a Leaflet basemap (GitHub #11): its devices at their real
 * coordinates, links drawn between them, co-located devices collapsed into a clickable stack
 * that fans out when opened. Scoped + zoomed to just this map's placed devices.
 */
export function MapLeafletCanvas() {
    const isAdmin = useIsAdmin();
    const activeMapId = useActiveMapId();
    const { data: config } = useMapConfig();
    const { data: mapDetail } = useMap(activeMapId);
    const { data: devices } = useDevices();
    const { data: links } = useLinks();
    const update = useUpdateDevice();
    useMapChannel(); // live status/util folded into the queries

    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<L.Map | null>(null);
    const markerLayer = useRef<L.LayerGroup | null>(null);
    const lineLayer = useRef<L.LayerGroup | null>(null);
    const draggingRef = useRef(false); // don't rebuild the layers mid-drag
    const [openStack, setOpenStack] = useState<string | null>(null);

    // This map's placed devices.
    const memberIds = useMemo(() => new Set((mapDetail?.positions ?? []).map((p) => p.device_id)), [mapDetail]);
    const placed = useMemo(
        () => (devices ?? []).filter((d) => memberIds.has(d.id) && d.latitude != null && d.longitude != null),
        [devices, memberIds],
    );
    const intraLinks = useMemo(
        () => (links ?? []).filter((l) => memberIds.has(l.a_device_id) && memberIds.has(l.b_device_id)),
        [links, memberIds],
    );
    const byId = useMemo(() => new Map(placed.map((d) => [d.id, d])), [placed]);

    const save = (id: number, lat: number, lng: number) =>
        update.mutate({ id, latitude: Number(lat.toFixed(7)), longitude: Number(lng.toFixed(7)) });

    // Init map.
    useEffect(() => {
        if (!config?.enabled || !containerRef.current || mapRef.current) return;
        const map = L.map(containerRef.current, { center: [20, 0], zoom: 3, worldCopyJump: true });
        L.tileLayer(config.tile_url, { attribution: config.attribution, maxZoom: 19 }).addTo(map);
        lineLayer.current = L.layerGroup().addTo(map);
        markerLayer.current = L.layerGroup().addTo(map);
        map.on('click', () => setOpenStack(null));
        mapRef.current = map;
        return () => { map.remove(); mapRef.current = null; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config?.enabled]);

    // Fit to this map's devices ONCE when the map first loads (or you switch maps) - never on a
    // drag or a status tick, so moving a pin doesn't yank the view back out.
    const lastFitMapId = useRef<number | null>(null);
    useEffect(() => {
        const map = mapRef.current;
        if (!map || activeMapId == null || placed.length === 0 || lastFitMapId.current === activeMapId) return;
        lastFitMapId.current = activeMapId;
        map.fitBounds(L.latLngBounds(placed.map((d) => [d.latitude as number, d.longitude as number])), { padding: [60, 60], maxZoom: 15 });
    }, [placed, activeMapId]);

    // Only rebuild the marker/line layers when something visible changes (positions, status,
    // stack open/close) - not on every unrelated query update, and never mid-drag.
    const sig = useMemo(() => {
        const dp = placed.map((d) => `${d.id}:${d.latitude}:${d.longitude}:${d.status}`).join('|');
        const lp = intraLinks.map((l) => {
            const down = byId.get(l.a_device_id)?.status === 'down' || byId.get(l.b_device_id)?.status === 'down';
            return `${l.id}:${down ? 'd' : 'u'}`;
        }).join('|');
        return `${dp}#${lp}#${openStack ?? ''}`;
    }, [placed, intraLinks, byId, openStack]);

    // Rebuild markers + lines when the signature changes (see above).
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !markerLayer.current || !lineLayer.current || draggingRef.current) return;
        markerLayer.current.clearLayers();
        lineLayer.current.clearLayers();

        // Links: a polyline between each pair of placed devices' coordinates.
        for (const l of intraLinks) {
            const a = byId.get(l.a_device_id);
            const b = byId.get(l.b_device_id);
            if (!a || !b) continue;
            const down = a.status === 'down' || b.status === 'down';
            L.polyline(
                [[a.latitude as number, a.longitude as number], [b.latitude as number, b.longitude as number]],
                { color: linkColor(linkUtil(l), down), weight: 2.5, opacity: down ? 0.5 : 0.85, dashArray: down ? '3 6' : undefined },
            ).addTo(lineLayer.current);
        }

        // Group by coordinate -> single pins or stacks.
        const groups = new Map<string, Device[]>();
        for (const d of placed) {
            const k = coordKey(d.latitude as number, d.longitude as number);
            (groups.get(k) ?? groups.set(k, []).get(k)!).push(d);
        }

        for (const [k, group] of groups) {
            const [lat, lng] = [group[0].latitude as number, group[0].longitude as number];

            if (group.length === 1) {
                addDeviceMarker(group[0], lat, lng);
                continue;
            }

            if (openStack === k) {
                // Fan the members out around the point so each is clickable/draggable.
                const r = 0.00035; // ~40m spread
                group.forEach((d, i) => {
                    const angle = (i / group.length) * Math.PI * 2;
                    const flat = lat + r * Math.sin(angle);
                    const flng = lng + r * Math.cos(angle);
                    L.polyline([[lat, lng], [flat, flng]], { color: 'rgba(255,255,255,0.25)', weight: 1 }).addTo(lineLayer.current!);
                    addDeviceMarker(d, flat, flng);
                });
            } else {
                // Collapsed stack: one marker with a count, click to open.
                const anyDown = group.some((d) => d.status === 'down');
                const color = anyDown ? STATUS_COLOR.down : STATUS_COLOR.up;
                const icon = L.divIcon({
                    className: '',
                    html: `<span style="display:grid;place-items:center;width:26px;height:26px;border-radius:50%;background:${color};color:#0d0d11;font:700 11px/1 ui-sans-serif;box-shadow:0 0 0 3px rgba(0,0,0,0.45);border:2px solid #0d0d11">${group.length}</span>`,
                    iconSize: [26, 26],
                    iconAnchor: [13, 13],
                });
                const m = L.marker([lat, lng], { icon, title: `${group.length} devices here` });
                m.on('click', (e) => { L.DomEvent.stopPropagation(e); setOpenStack(k); });
                m.addTo(markerLayer.current!);
            }
        }

        function addDeviceMarker(d: Device, lat: number, lng: number) {
            const m = L.marker([lat, lng], { icon: L.divIcon({ className: '', html: dotHtml(STATUS_COLOR[d.status] ?? STATUS_COLOR.unknown), iconSize: [14, 14], iconAnchor: [7, 7] }), draggable: isAdmin, title: d.name });
            m.bindTooltip(d.name, { direction: 'top', offset: [0, -8] });
            m.on('click', (e) => { L.DomEvent.stopPropagation(e); selectDevice(d.id); });
            m.on('dragstart', () => { draggingRef.current = true; });
            m.on('dragend', () => { draggingRef.current = false; const p = m.getLatLng(); save(d.id, p.lat, p.lng); setOpenStack(null); });
            m.addTo(markerLayer.current!);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sig, isAdmin]);

    if (config && !config.enabled) {
        return (
            <div className="grid h-full place-items-center p-8 text-center text-sm text-white/50">
                Geo mode needs a tile URL (MYMATE_MAP_TILE_URL) set.
            </div>
        );
    }

    return (
        <div className="relative h-full">
            <div ref={containerRef} className="h-full w-full bg-[#0d0d11]" style={{ zIndex: 0 }} />
            <div className="absolute left-4 top-4 z-[500] flex items-center gap-2">
                <MapSwitcher />
            </div>
            {placed.length === 0 && (
                <div className="pointer-events-none absolute inset-0 z-[500] grid place-items-center">
                    <div className="max-w-xs text-center">
                        <MapPin weight="light" className="mx-auto h-7 w-7 text-white/30" />
                        <p className="mt-2 text-sm text-white/70">No devices on this map have coordinates yet</p>
                        <p className="mt-1 text-xs text-white/40">Place them from the Geo map, or set a location on each device.</p>
                    </div>
                </div>
            )}
        </div>
    );
}
