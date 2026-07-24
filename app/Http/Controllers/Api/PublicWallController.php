<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Models\MapShare;
use App\Support\MapDetail;
use Illuminate\Http\JsonResponse;

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

        return response()->json(['data' => MapDetail::build($share->map)])
            ->header('Cache-Control', 'no-store');
    }

    /** The devices on this map, reduced to the fields the wallboard renders. Never any secrets. */
    public function devices(string $token): JsonResponse
    {
        $share = $this->share($token);

        $deviceIds = $share->map->positions()->pluck('device_id');
        $devices = \App\Models\Device::whereIn('id', $deviceIds)->get();

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
        ])->all();

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /**
     * A device's cached product photo (MikroTik), but only for a device actually on the shared
     * map. Delegates to the same controller the authenticated map uses - the image is just a
     * vendor product photo, nothing sensitive.
     */
    public function icon(string $token, \App\Models\Device $device, \App\Actions\Devices\FetchMikrotikIcon $icons)
    {
        $share = $this->share($token);
        abort_unless($share->map->positions()->where('device_id', $device->id)->exists(), 404);

        return app(DeviceIconController::class)->show($device, $icons);
    }

    /** The links among this map's devices (ids, media, live util/speed) - reuses LinkResource, which carries no secrets. */
    public function links(string $token): JsonResponse
    {
        $share = $this->share($token);

        $deviceIds = $share->map->positions()->pluck('device_id')->all();
        $links = Link::where(function ($q) use ($deviceIds): void {
            $q->whereIn('a_device_id', $deviceIds)->orWhereIn('b_device_id', $deviceIds);
        })->with(['aInterface', 'bInterface'])->get();

        return LinkResource::collection($links)->response()->header('Cache-Control', 'no-store');
    }
}
