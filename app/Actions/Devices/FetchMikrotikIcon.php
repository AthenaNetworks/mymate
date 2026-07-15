<?php

namespace App\Actions\Devices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve and cache a MikroTik device's product image from its model. MikroTik's CDN keys
 * images by an internal numeric id, so we derive the product-page slug from the model,
 * scrape the image id off the page, fetch the transparent product photo once, and store it
 * locally. Thereafter it's served from our own cache (no hotlinking, no re-fetch). Entirely
 * best-effort: a model whose slug doesn't resolve just leaves it to the drawn fallback icon.
 */
class FetchMikrotikIcon
{
    private const DIR = 'device-icons/mikrotik';

    /** Local storage path if this model's image is already cached, else null. */
    public function cachedPath(string $model): ?string
    {
        $slug = self::slug($model);
        if ($slug === '') {
            return null;
        }
        $path = self::DIR."/{$slug}.webp";

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    /** Fetch + cache the image for this model. Returns the stored path, or null on any failure. */
    public function fetch(string $model): ?string
    {
        $slug = self::slug($model);
        if ($slug === '') {
            return null;
        }

        try {
            $page = Http::timeout(6)->withHeaders(['User-Agent' => 'my-mate-device-icons'])
                ->get("https://mikrotik.com/product/{$slug}");
            if (! $page->successful()) {
                return null;
            }

            // The main product image lives at cdn.mikrotik.com/web-assets/rb_images/<id>_lg.webp.
            if (! preg_match('#rb_images/(\d+)_#', $page->body(), $m)) {
                return null;
            }

            $img = Http::timeout(8)->withHeaders(['User-Agent' => 'my-mate-device-icons'])
                ->get("https://cdn.mikrotik.com/web-assets/rb_images/{$m[1]}_lg.webp");
            if (! $img->successful() || $img->body() === '') {
                return null;
            }

            $path = self::DIR."/{$slug}.webp";
            Storage::disk('local')->put($path, $img->body());
            self::makeReadable($path);

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The `local` disk is private (0700 dirs / 0600 files by default), but these are public
     * product photos that the web-server process must be able to read - and on a packaged
     * install that process (php-fpm pool) runs as www-data, not the queue-worker user that
     * wrote the file. Widen the cache dir chain + file so any local user can serve it.
     */
    private static function makeReadable(string $path): void
    {
        $disk = Storage::disk('local');
        foreach ([self::DIR, dirname(self::DIR), $path] as $rel) {
            $abs = $disk->path($rel);
            @chmod($abs, is_dir($abs) ? 0755 : 0644);
        }
    }

    /** model / board-name -> product-page slug, e.g. "hAP ac²" -> "hap_ac2", "CRS328-24P-4S+" -> "crs328_24p_4s". */
    public static function slug(string $model): string
    {
        $s = str_replace(['²', '³'], ['2', '3'], mb_strtolower(trim($model)));
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);

        return trim($s, '_');
    }
}
