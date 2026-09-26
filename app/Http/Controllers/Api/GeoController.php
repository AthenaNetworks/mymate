<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\DeviceGeo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Geo-overlay support (GitHub #11): the tile/map config the SPA needs, and a server-side
 * geocoder proxy (address -> lat/lng) so the browser never talks to a third party directly.
 */
class GeoController extends Controller
{
    /** Tile URL + attribution for the Leaflet overlay; geo is disabled when tile_url is empty. */
    public function config(): JsonResponse
    {
        $tileUrl = (string) config('mymate.map.tile_url', '');

        return response()->json(['data' => [
            'enabled' => $tileUrl !== '',
            'tile_url' => $tileUrl,
            'attribution' => (string) config('mymate.map.tile_attribution', ''),
            'geocoder_enabled' => (string) config('mymate.map.geocoder_url', '') !== '',
        ]]);
    }

    /**
     * Compact placed-device feed for the geo map. Just what the map draws - id, name, status,
     * site, effective coordinates, and how long a down device has been down - so the map doesn't
     * have to pull the full multi-megabyte device resource just to plot dots and colour sites.
     *
     * Coordinates come from DeviceGeo, the same resolver the device resource uses (own pin, else
     * the site's, else the uplink chain) - the fleet is hydrated slim (the handful of columns
     * below) so resolving in one place stays affordable at large fleet sizes. Every device is
     * loaded so uplink inheritance can pass through unmonitored/unplaced parents, but only
     * monitored, placed devices are emitted (a paused device isn't on the live map).
     *
     * `down_since` is the still-open outage's `started_at` (the precise "went down" moment; a
     * device's `last_change` is overwritten on recovery too, so it can't answer this). Joined from
     * open outages grouped per device, so a racing poller that briefly leaves two open outages
     * can't emit the device twice. MIN() picks the earliest start, which is the honest answer for
     * how long the thing has actually been dark.
     */
    public function devices(): JsonResponse
    {
        // Plain rows, not models: this reads the whole fleet, and 25k hydrated devices cost about
        // 270 MB (GitHub #22). toBase() still applies the restricted-operator visibility scope.
        $rows = Device::query()
            ->leftJoinSub(
                DB::table('outages')->whereNull('ended_at')
                    ->selectRaw('device_id, MIN(started_at) AS down_since')->groupBy('device_id'),
                'open', 'open.device_id', '=', 'devices.id',
            )
            ->toBase()
            ->get(['devices.id', 'devices.name', 'devices.status', 'devices.monitored', 'devices.site_id',
                'devices.parent_device_id', 'devices.latitude', 'devices.longitude', 'open.down_since']);

        $sites = DB::table('sites')->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude'])
            ->mapWithKeys(fn ($s) => [(int) $s->id => [(float) $s->latitude, (float) $s->longitude]])
            ->all();

        $nodes = [];
        foreach ($rows as $r) {
            $nodes[(int) $r->id] = [$r->latitude, $r->longitude, $r->site_id !== null ? (int) $r->site_id : null,
                $r->parent_device_id !== null ? (int) $r->parent_device_id : null];
        }
        $geo = DeviceGeo::resolveAll($nodes, $sites);

        $out = [];
        foreach ($rows as $r) {
            [$lat, $lng] = $geo[(int) $r->id];
            if ($lat === null || ! Device::countsAsLive((bool) $r->monitored)) {
                continue;
            }
            $out[] = [
                'id' => (int) $r->id,
                'name' => $r->name,
                'status' => $r->status,
                'site_id' => $r->site_id !== null ? (int) $r->site_id : null,
                'lat' => $lat,
                'lng' => $lng,
                'down_since' => $r->down_since !== null ? Carbon::parse($r->down_since)->toIso8601String() : null,
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * Site-to-site backhaul links as coordinate pairs, for the geo map. Both ends resolved to
     * their site's coordinates in SQL; only links whose sites are both placed are returned.
     */
    public function backhauls(): JsonResponse
    {
        $rows = DB::table('site_links as l')
            ->join('sites as a', 'a.id', '=', 'l.site_a_id')
            ->join('sites as b', 'b.id', '=', 'l.site_b_id')
            ->whereNotNull('a.latitude')->whereNotNull('b.latitude')
            ->selectRaw('l.id, l.media_type, a.longitude AS a_lng, a.latitude AS a_lat, b.longitude AS b_lng, b.latitude AS b_lat')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'media_type' => $r->media_type,
                'a' => [(float) $r->a_lng, (float) $r->a_lat],
                'b' => [(float) $r->b_lng, (float) $r->b_lat],
            ]);

        return response()->json(['data' => $rows]);
    }

    /** Geocode an address to coordinates via the configured provider (proxied + best-effort). */
    public function geocode(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $url = (string) config('mymate.map.geocoder_url', '');
        if ($q === '' || $url === '') {
            return response()->json(['data' => null]);
        }

        try {
            // Fixed, trusted host (like the update check) - a valid User-Agent is required by
            // Nominatim's policy. Any failure just yields "no result", never an error.
            $res = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'my-mate-geocoder'])
                ->acceptJson()
                ->get($url, ['q' => $q, 'format' => 'json', 'limit' => 1]);

            $hit = $res->successful() ? ($res->json()[0] ?? null) : null;
            if ($hit === null || ! isset($hit['lat'], $hit['lon'])) {
                return response()->json(['data' => null]);
            }

            return response()->json(['data' => [
                'lat' => (float) $hit['lat'],
                'lng' => (float) $hit['lon'],
                'label' => (string) ($hit['display_name'] ?? $q),
            ]]);
        } catch (\Throwable) {
            return response()->json(['data' => null]);
        }
    }
}
