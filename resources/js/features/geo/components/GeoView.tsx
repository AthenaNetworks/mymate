import { useEffect, useMemo, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
// Marker clustering: an access network puts thousands of devices on a few thousand sites, all
// stacked on the same points. Drawing every marker at once locks the browser; the cluster group
// collapses them into count-bubbles that split apart as you zoom, so the whole fleet renders
// smoothly. The default cluster icons are CSS-drawn divs (no image requests), so it stays
// CSP-clean like the rest of the map.
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { MagnifyingGlass, MapPin, X } from '@phosphor-icons/react';
import { useDevices } from '../../devices/api/getDevices';
import { useUpdateDevice } from '../../devices/api/updateDevice';
import { useIsAdmin } from '../../auth/api/auth';
import { useMapConfig, useGeocode } from '../api/geo';
import { useBackhauls } from '../api/sites';
import { LayerToggle, loadLayerPrefs, persistLayerPrefs, type LayerPrefs } from './geoLayers';
import { pushToast } from '../../../lib/toast';
import type { Device, DeviceStatus } from '../../../types';

const STATUS_COLOR: Record<DeviceStatus, string> = { up: '#34d399', down: '#f43f5e', unknown: '#52525b' };

// What a device draws at: its own pin when it has one, otherwise its site's coordinates (the
// backend resolves this into geo_latitude/geo_longitude). A device sitting at a placed site is
// therefore "placed" here without an own pin, so it shows on the map and drops out of the
// unplaced list. Dragging it still writes its own latitude/longitude (an explicit override).
const geoLat = (d: Device): number | null => d.geo_latitude;
const geoLng = (d: Device): number | null => d.geo_longitude;

/** A status-coloured pin as an HTML div icon (no external marker images -> CSP-clean). */
function pinIcon(status: DeviceStatus): L.DivIcon {
    const c = STATUS_COLOR[status] ?? STATUS_COLOR.unknown;
    return L.divIcon({
        className: '',
        html: `<span style="display:block;width:14px;height:14px;border-radius:50%;background:${c};box-shadow:0 0 0 3px rgba(0,0,0,0.45),0 0 8px 1px ${c}99;border:2px solid #0d0d11"></span>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });
}

/**
 * Geographic overlay (GitHub #11): devices on a real map by lat/lng. Drag a pin to move it,
 * or place an unplaced device by geocoding an address or clicking the map. Tiles come from the
 * configured provider (see mymate.map.tile_url).
 */
export function GeoView() {
    const isAdmin = useIsAdmin();
    const { data: config } = useMapConfig();
    const { data: devices } = useDevices();
    const { data: backhauls } = useBackhauls();
    const update = useUpdateDevice();
    const geocode = useGeocode();

    // Which layers are on (persisted across reloads, shared with the other geo renderers).
    const [layers, setLayers] = useState<LayerPrefs>(loadLayerPrefs);
    const toggleLayer = (key: keyof LayerPrefs) =>
        setLayers((prev) => {
            const next = { ...prev, [key]: !prev[key] };
            persistLayerPrefs(next);
            return next;
        });

    const mapRef = useRef<L.Map | null>(null);
    // Effects that draw on the map key off this, not mapRef: the map is created
    // asynchronously (once the config query resolves), and by then the device list -
    // usually cached from the topology view - has already settled, so ref-only
    // consumers would never re-run and the first open would show an unfitted,
    // pinless world map.
    const [mapReady, setMapReady] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const markersRef = useRef<Map<number, L.Marker>>(new Map());
    const clusterRef = useRef<L.MarkerClusterGroup | null>(null);
    const backhaulLayerRef = useRef<L.LayerGroup | null>(null);
    const [placingId, setPlacingId] = useState<number | null>(null); // device awaiting a click-to-place
    const [address, setAddress] = useState('');

    const placed = useMemo(() => (devices ?? []).filter((d) => geoLat(d) != null && geoLng(d) != null), [devices]);
    const unplaced = useMemo(() => (devices ?? []).filter((d) => geoLat(d) == null || geoLng(d) == null), [devices]);
    const placingRef = useRef<number | null>(null);
    placingRef.current = placingId;

    const save = (id: number, lat: number, lng: number) =>
        update.mutate({ id, latitude: Number(lat.toFixed(7)), longitude: Number(lng.toFixed(7)) });

    // Init the Leaflet map once.
    useEffect(() => {
        if (!config?.enabled || !containerRef.current || mapRef.current) return;
        const map = L.map(containerRef.current, { center: [20, 0], zoom: 2, worldCopyJump: true });
        L.tileLayer(config.tile_url, { attribution: config.attribution, maxZoom: 19 }).addTo(map);
        mapRef.current = map;

        // One cluster group holds every device marker. chunkedLoading keeps the main thread
        // free while thousands are added; spiderfy fans out the co-located devices at a site
        // when you click its cluster at max zoom.
        const cluster = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 48, spiderfyOnMaxZoom: true });
        cluster.addTo(map);
        clusterRef.current = cluster;

        // Backhaul lines live in their own group, under the markers, so the toggle can add and
        // remove them without touching the cluster.
        backhaulLayerRef.current = L.layerGroup();
        setMapReady(true);

        // Click the map to drop the device currently being placed.
        map.on('click', (e: L.LeafletMouseEvent) => {
            const id = placingRef.current;
            if (id != null) {
                save(id, e.latlng.lat, e.latlng.lng);
                setPlacingId(null);
            }
        });

        return () => {
            map.remove();
            mapRef.current = null;
            clusterRef.current = null;
            backhaulLayerRef.current = null;
            markersRef.current.clear();
            setMapReady(false);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config?.enabled]);

    // Sync markers (inside the cluster group) to the placed devices. Add/remove go through the
    // cluster group so it re-clusters incrementally rather than redrawing the whole fleet.
    useEffect(() => {
        const cluster = clusterRef.current;
        if (!cluster) return;
        const seen = new Set<number>();
        const toAdd: L.Marker[] = [];

        for (const d of placed) {
            seen.add(d.id);
            const pos: L.LatLngExpression = [geoLat(d) as number, geoLng(d) as number];
            let marker = markersRef.current.get(d.id);
            if (!marker) {
                marker = L.marker(pos, { icon: pinIcon(d.status), draggable: isAdmin, title: d.name });
                marker.bindTooltip(d.name, { direction: 'top', offset: [0, -8] });
                marker.on('dragend', () => { const p = marker!.getLatLng(); save(d.id, p.lat, p.lng); });
                markersRef.current.set(d.id, marker);
                toAdd.push(marker);
            } else {
                marker.setLatLng(pos);
                marker.setIcon(pinIcon(d.status));
            }
        }
        if (toAdd.length) cluster.addLayers(toAdd);

        // Drop markers for devices no longer placed.
        const toRemove: L.Marker[] = [];
        for (const [id, marker] of markersRef.current) {
            if (!seen.has(id)) { toRemove.push(marker); markersRef.current.delete(id); }
        }
        if (toRemove.length) cluster.removeLayers(toRemove);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [placed, isAdmin, mapReady]);

    // Device-layer toggle: the cluster group leaves/joins the map whole. The markers inside it
    // stay synced by the effect above either way, so re-enabling is instant.
    useEffect(() => {
        const map = mapRef.current;
        const cluster = clusterRef.current;
        if (!map || !cluster) return;
        if (layers.sites && !map.hasLayer(cluster)) cluster.addTo(map);
        if (!layers.sites && map.hasLayer(cluster)) map.removeLayer(cluster);
    }, [layers.sites, mapReady]);

    // Backhaul lines: site-to-site links from the OSS backbone topology, drawn under the
    // markers - solid for fiber, dashed for wireless (matching the vector renderer). Rebuilt
    // whole on data change; the topology is a few hundred lines at most, so that's cheap.
    useEffect(() => {
        const map = mapRef.current;
        const group = backhaulLayerRef.current;
        if (!map || !group) return;
        group.clearLayers();
        if (layers.backhauls) {
            for (const l of backhauls ?? []) {
                const wireless = l.media_type === 'wireless';
                group.addLayer(L.polyline(
                    [[l.a[1], l.a[0]], [l.b[1], l.b[0]]], // feed is [lng, lat]
                    wireless
                        ? { color: '#8b9cb3', weight: 1.5, opacity: 0.55, dashArray: '4 3', interactive: false }
                        : { color: '#5b8def', weight: 1.5, opacity: 0.6, interactive: false },
                ));
            }
            if (!map.hasLayer(group)) group.addTo(map);
        } else if (map.hasLayer(group)) {
            map.removeLayer(group);
        }
    }, [backhauls, layers.backhauls, mapReady]);

    // Fit to the placed devices the first time there are any.
    const fittedRef = useRef(false);
    useEffect(() => {
        const map = mapRef.current;
        if (!map || fittedRef.current || placed.length === 0) return;
        fittedRef.current = true;
        const bounds = L.latLngBounds(placed.map((d) => [geoLat(d) as number, geoLng(d) as number] as L.LatLngExpression));
        map.fitBounds(bounds, { padding: [60, 60], maxZoom: 14 });
    }, [placed, mapReady]);

    async function placeByAddress(device: Device) {
        if (address.trim() === '') return;
        const hit = await geocode.mutateAsync(address.trim());
        if (!hit) { pushToast({ title: 'No match for that address', tone: 'down' }); return; }
        save(device.id, hit.lat, hit.lng);
        setPlacingId(null);
        setAddress('');
        mapRef.current?.flyTo([hit.lat, hit.lng], 14);
    }

    if (config && !config.enabled) {
        return (
            <div className="grid h-full place-items-center p-8 text-center">
                <div className="max-w-sm">
                    <MapPin weight="light" className="mx-auto h-8 w-8 text-white/30" />
                    <p className="mt-3 text-sm font-medium text-white/70">Geographic overlay is disabled</p>
                    <p className="mt-1 text-xs text-white/40">Set a tile URL (MYMATE_MAP_TILE_URL) to enable it.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="relative flex h-full">
            <div ref={containerRef} className="h-full flex-1 bg-surface" style={{ zIndex: 0 }} />

            {/* Layer toggles - what's on the map is a matter of what the operator is doing. */}
            <div className="absolute bottom-4 left-3 z-[500] flex gap-1.5">
                <LayerToggle label="Devices" on={layers.sites} onClick={() => toggleLayer('sites')} title="Show device markers" />
                <LayerToggle label="Backhauls" on={layers.backhauls} onClick={() => toggleLayer('backhauls')} title="Show site-to-site backhaul lines" />
            </div>

            {/* Placement panel (admin) - place devices that have no coordinates yet. */}
            {isAdmin && (
                <div className="absolute right-3 top-3 z-[500] w-72 rounded-2xl bg-surface/95 p-3 ring-1 ring-white/10 backdrop-blur-xl">
                    <div className="flex items-center justify-between px-1">
                        <span className="text-xs font-semibold text-white/80">Place devices</span>
                        <span className="text-[11px] text-white/40">{unplaced.length} unplaced</span>
                    </div>
                    {placingId != null ? (
                        <div className="mt-2 space-y-2">
                            <p className="px-1 text-[11px] leading-snug text-emerald-300/90">
                                Click the map to drop <span className="font-semibold">{devices?.find((d) => d.id === placingId)?.name}</span>, or find an address:
                            </p>
                            {config?.geocoder_enabled && (
                                <div className="flex items-center gap-1.5 rounded-lg bg-white/[0.04] px-2 py-1.5 ring-1 ring-white/10">
                                    <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/40" />
                                    <input
                                        autoFocus
                                        value={address}
                                        onChange={(e) => setAddress(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') { const d = devices?.find((x) => x.id === placingId); if (d) placeByAddress(d); } }}
                                        placeholder="Address or place"
                                        className="w-full bg-transparent text-sm text-white outline-none placeholder:text-white/30"
                                    />
                                </div>
                            )}
                            <button onClick={() => { setPlacingId(null); setAddress(''); }} className="flex items-center gap-1 px-1 text-[11px] text-white/45 hover:text-white/80">
                                <X weight="bold" className="h-3 w-3" /> Cancel
                            </button>
                        </div>
                    ) : (
                        <ul className="mt-2 max-h-72 space-y-0.5 overflow-auto">
                            {unplaced.length === 0 ? (
                                <li className="px-2 py-3 text-center text-[11px] text-white/35">Every device is placed.</li>
                            ) : (
                                unplaced.slice(0, 200).map((d) => (
                                    <li key={d.id}>
                                        <button
                                            onClick={() => setPlacingId(d.id)}
                                            className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-white/80 transition-colors hover:bg-white/5"
                                        >
                                            <MapPin weight="bold" className="h-3.5 w-3.5 shrink-0 text-emerald-300" />
                                            <span className="min-w-0 flex-1 truncate">{d.name}</span>
                                            <span className="shrink-0 font-mono text-[10px] text-white/35">{d.mgmt_ip}</span>
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
