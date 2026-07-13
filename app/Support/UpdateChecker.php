<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks whether a newer My Mate release is available, by comparing this install's version
 * against the latest GitHub release tag. The GitHub lookup is cached (it's a fixed, trusted
 * host - not an operator-supplied URL, so it's not run through OutboundHostGuard) and every
 * failure is swallowed: a check that can't reach GitHub just reports "no update known",
 * never an error.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'mymate.update.latest_tag';

    /** This install's version (without a leading v), or "dev" when unknown. */
    public function current(): string
    {
        $v = (string) config('mymate.update.version');
        if ($v === '') {
            $file = base_path('VERSION');
            $v = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        }

        return $v !== '' ? ltrim($v, 'vV') : 'dev';
    }

    /**
     * @return array{current:string, latest:?string, update_available:bool, url:?string, checked_at:string}
     */
    public function check(bool $fresh = false): array
    {
        $current = $this->current();
        $latest = $this->latestTag($fresh);
        $repo = (string) config('mymate.update.repo');

        $available = $latest !== null
            && $current !== 'dev'
            && version_compare(ltrim($latest, 'vV'), $current, '>');

        return [
            'current' => $current,
            'latest' => $latest,
            'update_available' => $available,
            'url' => $latest !== null ? "https://github.com/{$repo}/releases/latest" : null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** The latest release tag from GitHub, cached; null when disabled or unreachable. */
    private function latestTag(bool $fresh): ?string
    {
        if (! config('mymate.update.enabled', true)) {
            return null;
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        // Cache::remember re-runs when the closure returns null, so a transient failure
        // isn't cached - the next check retries.
        return Cache::remember(self::CACHE_KEY, now()->addHours(max(1, (int) config('mymate.update.cache_hours', 12))), function (): ?string {
            return $this->fetchLatestTag();
        });
    }

    private function fetchLatestTag(): ?string
    {
        try {
            $repo = (string) config('mymate.update.repo');
            $res = Http::timeout(8)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'my-mate-update-check'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $res->successful()) {
                return null;
            }
            $tag = (string) $res->json('tag_name');

            return $tag !== '' ? $tag : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
