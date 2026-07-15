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

    /**
     * MikroTik's product-page slugs use the marketing name (e.g. "hAP ac²" -> hap_ac2), but SNMP
     * reports the board code ("RBD53iG-5HacD2HnD"). This maps the board-code prefix to the
     * marketing slug for the families where the two diverge. Each entry is verified to resolve to
     * a real product page + image; a wrong photo is worse than none, so only add verified rows.
     */
    private const ALIASES = [
        'RBD53' => 'hap_ac2',
        'RBD52' => 'hap_ac2',
        'RBD22' => 'hap_ax_lite',
        'C52iG' => 'hap_ax2',
        'C53' => 'hap_ax3',
        'cAPGi-5Hax' => 'cap_ax',
        'cAPGi-5ac' => 'cap_ac',
        'RBcAPGi-5Hac' => 'cap_ac',
        'RBcAPGi-5ac' => 'cap_ac',
        'RBwAPGR' => 'wap_ac',
        'RBwAPG' => 'wap_ac',
        'wAPG-5Hax' => 'wap_ax',
        'wAPR' => 'wap_lte_kit',
    ];

    /**
     * Ordered product-page slugs to try for a model: the verified marketing alias first, then the
     * board-code slug and its country-variant suffixes (MikroTik pages are often only reachable as
     * `<slug>_in` / `<slug>_us`). The stored filename is always the base slug so cachedPath() finds it.
     *
     * @return list<string>
     */
    private static function candidateSlugs(string $model): array
    {
        $base = self::slug($model);
        $slugs = [];
        foreach (self::ALIASES as $prefix => $alias) {
            if (stripos($model, $prefix) === 0) {
                $slugs[] = $alias;
                break;
            }
        }
        foreach ([$base, $base.'_in', $base.'_us'] as $s) {
            if ($s !== '' && ! in_array($s, $slugs, true)) {
                $slugs[] = $s;
            }
        }

        return $slugs;
    }

    /** Fetch + cache the image for this model. Returns the stored path, or null on any failure. */
    public function fetch(string $model): ?string
    {
        $base = self::slug($model);
        if ($base === '') {
            return null;
        }

        foreach (self::candidateSlugs($model) as $slug) {
            try {
                $page = Http::timeout(6)->withHeaders(['User-Agent' => 'my-mate-device-icons'])
                    ->get("https://mikrotik.com/product/{$slug}");
                // The main product image lives at cdn.mikrotik.com/web-assets/rb_images/<id>_lg.webp.
                if (! $page->successful() || ! preg_match('#rb_images/(\d+)_#', $page->body(), $m)) {
                    continue; // try the next candidate slug
                }

                $img = Http::timeout(8)->withHeaders(['User-Agent' => 'my-mate-device-icons'])
                    ->get("https://cdn.mikrotik.com/web-assets/rb_images/{$m[1]}_lg.webp");
                if (! $img->successful() || $img->body() === '') {
                    continue;
                }

                // Always store under the base slug so cachedPath($model) finds it regardless of
                // which candidate resolved.
                $path = self::DIR."/{$base}.webp";
                Storage::disk('local')->put($path, $img->body());
                self::makeReadable($path);

                return $path;
            } catch (\Throwable) {
                // network hiccup on this candidate - try the next.
            }
        }

        return null;
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
