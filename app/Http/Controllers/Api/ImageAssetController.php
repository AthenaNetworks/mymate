<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImageAsset;
use App\Support\ImageUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The image library (GitHub #53): upload once, then use an image as a device's icon or place it on
 * maps. Uploads are checked by their bytes ({@see ImageUpload}, SVG sanitised) and stored on the
 * private disk; files only come back through {@see self::serve()} with a sandboxed CSP. Reading is
 * open to any operator (the map draws them); upload and delete are admin-only.
 */
class ImageAssetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ImageAsset::orderBy('name')->get()->map->meta()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $maxKb = (int) config('mymate.map.image_max_kb', 2048);
        $data = $request->validate([
            'image' => ['required', 'file', 'max:'.$maxKb],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], [
            'image.max' => "The image is larger than the {$maxKb} KB limit.",
        ]);

        $file = $request->file('image');
        [$mime, $bytes, $width, $height] = ImageUpload::inspect($file, (int) config('mymate.map.image_max_px', 4096));

        $path = ImageAsset::DIR.'/'.Str::random(24).'.'.ImageUpload::TYPES[$mime];
        Storage::disk('local')->put($path, $bytes);

        $name = trim((string) ($data['name'] ?? '')) ?: (pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Image');
        $asset = ImageAsset::create([
            'name' => Str::limit($name, 120, ''),
            'path' => $path,
            'mime' => $mime,
            'version' => Str::random(12),
            'width' => $width,
            'height' => $height,
        ]);

        return response()->json(['data' => $asset->meta()], Response::HTTP_CREATED);
    }

    public function file(ImageAsset $imageAsset): BinaryFileResponse
    {
        return self::serve($imageAsset);
    }

    /** Removes it from every map it's on, and devices using it as their icon go back to automatic. */
    public function destroy(ImageAsset $imageAsset): Response
    {
        $imageAsset->delete();

        return response()->noContent();
    }

    /**
     * Stream an image. Shared with the public wallboard, which checks first that the image is in
     * use on the shared map. Same headers as a map background: versioned URL so it caches hard,
     * and a CSP that lets nothing in an opened-directly SVG run or load.
     */
    public static function serve(ImageAsset $asset): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return response()->file(Storage::disk('local')->path($asset->path), [
            'Content-Type' => $asset->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=604800',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
