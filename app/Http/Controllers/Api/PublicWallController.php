<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\FetchMikrotikIcon;
use App\Http\Controllers\Controller;
use App\Http\Resources\LinkResource;
use App\Models\Device;
use App\Models\Link;
use App\Models\MapShare;
use App\Support\DeviceGeo;
use App\Support\MapDetail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated wallboard endpoints (GitHub #15). Every action is gated only by the
 * share token in the URL and only ever reads. The device payload is a hand-built whitelist of
 * the fields the map draws - no management addresses, credentials, agent tokens or serials ever
 * cross this boundary. Routes are rate-limited (see routes/api.php).
 */
class PublicWallController extends Controller
{
    /** Resolve an enabled share by token, else 404. */
    private function share(string $token): MapShare
    {
        $share = MapShare::where('token', $token)->where('enabled', true)->with('map')->first();
        abort_if($share === null || $share->map === null, 404);

        return $share;
    }

    /** The map's render payload (positions, portals, child maps, notes) - same shape as MapController@show. */
    public function map(string $token): JsonResponse
    {
        $share = $this->share($token);

        // Stamp recency here only (once per poll cycle, not on every devices/links/icon call).
        // Best-effort and event-free so it never blocks the view or fires a broadcast.
        $share->forceFill(['last_viewed_at' => now()])->saveQuietly();

        // The background placement rides along here (it's not part of MapDetail - the logged-in
        // canvas reads it from its own endpoint so editing it never churns the node data).
        // `share.view` tells the page which view(s) this link may show (GitHub #37).
        $data = MapDetail::build($share->map) + ['background' => $share->map->backgroundMeta()];

        return response()->json(['data' => $data, 'share' => ['view' => $share->view]])
            ->header('Cache-Control', 'no-store');
    }

    /** The shared map's background image (GitHub #37), if it has one. Same sandboxed response as the authenticated route. */
    public function background(string $token)
    {
        return MapBackgroundController::serve($this->share($token)->map);
    }

    /**
     * Basemap tiles for a geo share (GitHub #37) - the same URL + attribution every operator's
     * browser already gets, since the browser fetches the tiles itself. A logical-only share
     * never gets it: nothing geographic leaves through a link that wasn't made for it.
     */
    public function mapConfig(string $token): JsonResponse
    {
        $share = $this->share($token);
        abort_unless($share->showsGeo(), 404);

        $tileUrl = (string) config('mymate.map.tile_url', '');

        return response()->json(['data' => [
            'enabled' => $tileUrl !== '',
            'tile_url' => $tileUrl,
            'attribution' => (string) config('mymate.map.tile_attribution', ''),
            // No address search on a public page - that's a write-side tool and a proxied third party.
            'geocoder_enabled' => false,
        ]])->header('Cache-Control', 'no-store');
    }

    /** The devices on this map, reduced to the fields the wallboard renders. Never any secrets. */
    public function devices(string $token): JsonResponse
    {
        $share = $this->share($token);

        $deviceIds = $share->map->positions()->pluck('device_id');
        $devices = Device::whereIn('id', $deviceIds)->get();

        $geo = $share->showsGeo();
        if ($geo) {
            $this->resolveGeo($devices);
        }

        $data = $devices->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            // Deliberately NOT mgmt_ip - a public link must not leak internal addressing.
            'mgmt_ip' => null,
            'status' => $d->status->value,
            'map_x' => $d->map_x,
            'map_y' => $d->map_y,
            'device_type' => $d->device_type?->value ?? 'unknown',
            'icon' => $d->icon,
            'icon_color' => $d->icon_color,
            'vendor' => $d->vendor,
            'model' => $d->model,
            'cpu_pct' => $d->cpu_pct,
            'mem_used_pct' => $d->mem_used_pct,
            'temp_c' => $d->temp_c,
            'rtt_ms' => $d->rtt_ms,
            'loss_pct' => $d->loss_pct,
            'latency_good_ms' => $d->latency_good_ms,
            'latency_bad_ms' => $d->latency_bad_ms,
        ] + ($geo ? [
            // Only on a geo share (GitHub #37), and only the effective position the geo map draws
            // at - not the raw pin, its source, the SNMP coords or the site it came from.
            'geo_latitude' => $d->geo_latitude,
            'geo_longitude' => $d->geo_longitude,
        ] : []))->all();

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /**
     * A device's cached product photo (MikroTik), but only for a device actually on the shared
     * map. Delegates to the same controller the authenticated map uses - the image is just a
     * vendor product photo, nothing sensitive.
     */
    public function icon(string $token, Device $device, FetchMikrotikIcon $icons)
    {
        $share = $this->share($token);
        abort_unless($share->map->positions()->where('device_id', $device->id)->exists(), 404);

        return app(DeviceIconController::class)->show($device, $icons);
    }

    /** A model's photo for this wallboard: only for a model some device on the shared map has. */
    public function iconByModel(Request $request, string $token, FetchMikrotikIcon $icons)
    {
        $share = $this->share($token);
        $model = (string) $request->query('model', '');
        abort_if($model === '' || mb_strlen($model) > 100, 404);

        $device = Device::whereIn('id', $share->map->positions()->select('device_id'))
            ->where('model', $model)->where('vendor', 'ilike', '%mikrotik%')
            ->first(['id', 'vendor', 'model']);
        abort_if($device === null, 404);

        return app(DeviceIconController::class)->show($device, $icons);
    }

    /**
     * Resolve each map device's effective coordinates exactly as the authenticated geo map does
     * (own pin, else its site, else up the uplink chain). The chain can run through parents that
     * aren't on this map, so those are pulled in too, slim, just to be walked - they're never
     * emitted, all that surfaces is the map device's own resulting position.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function resolveGeo(Collection $devices): void
    {
        $all = $devices->keyBy('id');
        $pending = $devices->pluck('parent_device_id')->filter()->unique()->reject(fn ($id) => $all->has($id));

        // Bounded so a parent loop (or an absurdly deep tree) can't spin here.
        for ($depth = 0; $pending->isNotEmpty() && $depth < 32; $depth++) {
            $parents = Device::whereIn('id', $pending->values())->get(['id', 'parent_device_id', 'latitude', 'longitude', 'site_id']);
            foreach ($parents as $parent) {
                $all->put($parent->id, $parent);
            }
            $pending = $parents->pluck('parent_device_id')->filter()->unique()->reject(fn ($id) => $all->has($id));
        }

        DeviceGeo::apply($all->values());
    }

    /** The links among this map's devices (ids, media, live util/speed) - reuses LinkResource, which carries no secrets. */
    public function links(string $token): JsonResponse
    {
        $share = $this->share($token);

        // Both ends must be on the shared map. A link with one end off it can't be drawn anyway, and
        // handing it out would leak the far device's id and its port names/traffic to an anonymous
        // viewer who was only given this map.
        $deviceIds = $share->map->positions()->pluck('device_id')->all();
        $links = Link::whereIn('a_device_id', $deviceIds)->whereIn('b_device_id', $deviceIds)
            ->with(['aInterface', 'bInterface'])->get();

        return LinkResource::collection($links)->response()->header('Cache-Control', 'no-store');
    }
}
