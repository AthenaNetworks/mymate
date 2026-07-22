<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
