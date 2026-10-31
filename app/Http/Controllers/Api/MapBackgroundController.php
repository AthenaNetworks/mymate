<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Map;
use App\Support\ImageUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A map's custom background image (GitHub #37) - a floor plan, site photo or rack diagram drawn
 * behind the devices. Viewing follows map visibility: {map} is resolved through the Map global
 * scope, so a restricted operator gets a 404 for a map they can't see. Upload/placement/remove are
 * admin-only (the `admin` middleware on those routes).
 *
 * The type is decided from the file's bytes, never the client's filename or MIME: PNG/JPEG/WebP
 * must pass getimagesize(), and SVG goes through SvgSanitizer (script, handlers and external refs
 * stripped, DOCTYPE refused). Files live on the private local disk under map-backgrounds/ and are
 * only ever streamed back through {@see self::serve()}, with a sandboxed CSP on top.
 */
class MapBackgroundController extends Controller
{
    public const DIR = 'map-backgrounds';


    public function show(Map $map): JsonResponse
    {
        return response()->json(['data' => $map->backgroundMeta()]);
    }

    public function image(Map $map): BinaryFileResponse
    {
        return self::serve($map);
    }

    public function store(Request $request, Map $map): JsonResponse
    {
        $maxKb = (int) config('mymate.map.background_max_kb', 10240);
        $request->validate([
            'image' => ['required', 'file', 'max:'.$maxKb],
        ], [
            'image.max' => "The image is larger than the {$maxKb} KB limit.",
        ]);

        [$mime, $bytes, $width, $height] = ImageUpload::inspect($request->file('image'), (int) config('mymate.map.background_max_px', 16384));

        $old = $map->background_path;
        $path = self::DIR.'/'.$map->id.'-'.Str::random(16).'.'.ImageUpload::TYPES[$mime];
        Storage::disk('local')->put($path, $bytes);

        // A fresh image keeps the admin's placement (swapping a floor plan for an updated one
        // shouldn't throw it back to 0,0) - only the file, type and size change.
        $map->forceFill([
            'background_path' => $path,
            'background_mime' => $mime,
            'background_version' => Str::random(12),
            'background_width' => $width,
            'background_height' => $height,
        ])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $map->backgroundMeta()], Response::HTTP_CREATED);
    }

    /** Placement only: where it sits, how big, how faint. */
    public function update(Request $request, Map $map): JsonResponse
    {
        abort_unless($map->background_path, 404);

        $data = $request->validate([
            'x' => ['sometimes', 'numeric', 'between:-1000000,1000000'],
            'y' => ['sometimes', 'numeric', 'between:-1000000,1000000'],
            'scale' => ['sometimes', 'numeric', 'between:0.01,100'],
            'opacity' => ['sometimes', 'numeric', 'between:0,1'],
            // Repeat the image across the whole canvas, wallpaper style (GitHub #53).
            'tile' => ['sometimes', 'boolean'],
        ]);

        $fill = [];
        foreach ($data as $k => $v) {
            $fill['background_'.$k] = $k === 'tile' ? (bool) $v : (float) $v;
        }
        $map->forceFill($fill)->save();

        return response()->json(['data' => $map->backgroundMeta()]);
    }

    public function destroy(Map $map): Response
    {
        if ($map->background_path) {
            Storage::disk('local')->delete($map->background_path);
        }
        $map->forceFill([
            'background_path' => null,
            'background_mime' => null,
            'background_version' => null,
            'background_width' => null,
            'background_height' => null,
            'background_x' => 0,
            'background_y' => 0,
            'background_scale' => 1,
            'background_opacity' => 0.6,
            'background_tile' => false,
        ])->save();

        return response()->noContent();
    }

    /**
     * Stream a map's background. Shared with the public wallboard, which has already checked the
     * share token. The URL carries the version, so it can be cached hard; `private` keeps it out of
     * shared caches either way. The CSP is belt-and-braces for someone opening the image directly:
     * nothing in it may run or load, and `sandbox` gives it an opaque origin.
     */
    public static function serve(Map $map): BinaryFileResponse
    {
        $path = $map->background_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $map->background_mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=604800',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
