<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Map;
use App\Support\SvgSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
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

    /** mime => file extension for everything we accept. */
    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

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

        [$mime, $bytes, $width, $height] = $this->inspect($request->file('image'));

        $old = $map->background_path;
        $path = self::DIR.'/'.$map->id.'-'.Str::random(16).'.'.self::TYPES[$mime];
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
        ]);

        $fill = [];
        foreach ($data as $k => $v) {
            $fill['background_'.$k] = (float) $v;
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

    /**
     * Work out what the upload really is from its bytes, and hand back what to store.
     *
     * @return array{0:string, 1:string, 2:int, 3:int} [mime, bytes, width, height]
     */
    private function inspect(UploadedFile $file): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $maxPx = (int) config('mymate.map.background_max_px', 16384);
        $reject = fn (string $msg) => abort(response()->json([
            'message' => $msg,
            'errors' => ['image' => [$msg]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));

        $raster = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };

        if ($raster !== null) {
            $info = @getimagesizefromstring($bytes);
            if ($info === false || ($info['mime'] ?? null) !== $raster || $info[0] < 1 || $info[1] < 1) {
                $reject('That image looks damaged - it could not be read.');
            }
            if ($info[0] > $maxPx || $info[1] > $maxPx) {
                $reject("That image is too large - keep each side under {$maxPx} pixels.");
            }

            return [$raster, $bytes, (int) $info[0], (int) $info[1]];
        }

        // Not a raster we know; the only other thing we take is SVG.
        $svg = SvgSanitizer::sanitize($bytes);
        if ($svg === null) {
            $reject('Use a PNG, JPEG, WebP or SVG image.');
        }
        [$w, $h] = $this->svgSize($svg);

        return ['image/svg+xml', $svg, $w, $h];
    }

    /**
     * An SVG's intrinsic size from width/height, else its viewBox, else a sane default. Only used to
     * lay the image out before it loads - the browser still renders the vector at any scale.
     *
     * @return array{0:int, 1:int}
     */
    private function svgSize(string $svg): array
    {
        $doc = new \DOMDocument;
        $doc->loadXML($svg, LIBXML_NONET);
        $root = $doc->documentElement;

        $num = fn (?string $v) => $v !== null && preg_match('/^\s*([0-9.]+)\s*(px)?\s*$/', $v, $m) ? (float) $m[1] : null;
        $w = $num($root?->getAttribute('width') ?: null);
        $h = $num($root?->getAttribute('height') ?: null);

        if (($w === null || $h === null) && $root && preg_match('/^\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*$/', $root->getAttribute('viewBox'), $m)) {
            $w ??= (float) $m[1];
            $h ??= (float) $m[2];
        }

        $clamp = fn (?float $v) => (int) max(1, min((int) config('mymate.map.background_max_px', 16384), round($v ?? 1000)));

        return [$clamp($w), $clamp($h)];
    }
}
