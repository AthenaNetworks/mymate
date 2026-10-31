<?php

namespace App\Support;

use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * Decide what an uploaded image really is from its bytes, never the client's filename or MIME, for
 * every image an operator can upload (map backgrounds GitHub #37, the image library GitHub #53).
 * PNG/JPEG/WebP must pass getimagesize(); SVG goes through SvgSanitizer (script, handlers and
 * external refs stripped, DOCTYPE refused). Anything else is a 422 on the `image` field.
 */
class ImageUpload
{
    /** mime => file extension for everything we accept. */
    public const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /**
     * Work out what the upload really is from its bytes, and hand back what to store.
     *
     * @return array{0:string, 1:string, 2:int, 3:int} [mime, bytes, width, height]
     */
    public static function inspect(UploadedFile $file, int $maxPx): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
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
        [$w, $h] = self::svgSize($svg, $maxPx);

        return ['image/svg+xml', $svg, $w, $h];
    }

    /**
     * An SVG's intrinsic size from width/height, else its viewBox, else a sane default. Only used to
     * lay the image out before it loads - the browser still renders the vector at any scale.
     *
     * @return array{0:int, 1:int}
     */
    private static function svgSize(string $svg, int $maxPx): array
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

        $clamp = fn (?float $v) => (int) max(1, min($maxPx, round($v ?? 1000)));

        return [$clamp($w), $clamp($h)];
    }
}
