<?php

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the MikroTik download server: the newest version per release channel (from the
 * `NEWEST{major}.{channel}` metadata files) for the upgrade picker, and the per-arch `.npk`
 * URL / existence for any chosen version. Pure metadata + URL building - the actual download
 * lives in FetchRouterosPackage.
 */
class RouterosReleases
{
    /**
     * RouterOS CPU architectures we can target (from /system/resource architecture-name).
     * `x86_64` is what a CHR (Cloud Hosted Router) reports; RouterOS 7 serves it - and physical
     * x86 - from the single arch-less package (see packageFilename).
     */
    public const ARCHES = ['arm', 'arm64', 'mipsbe', 'mmips', 'smips', 'tile', 'ppc', 'x86', 'x86_64', 'e500'];

    private function base(): string
    {
        return (string) config('mymate.upgrade.download_base', 'https://download.mikrotik.com/routeros');
    }

    /**
     * Latest version in each channel for RouterOS 7 and 6, for the version picker. Cached 1h;
     * unreachable channels are simply omitted (never an error).
     *
     * @return list<array{major:int, channel:string, version:string, released_at:?int}>
     */
    public function channels(): array
    {
        return Cache::remember('routeros.channels', now()->addHour(), function (): array {
            $out = [];
            /** @var list<string> $channels */
            $channels = (array) config('mymate.upgrade.channels', ['stable', 'long-term', 'testing']);

            foreach ([7, 6] as $major) {
                foreach ($channels as $channel) {
                    $line = $this->fetchNewest($major, $channel);
                    if ($line === null) {
                        continue;
                    }
                    [$version, $ts] = array_pad(preg_split('/\s+/', trim($line)) ?: [], 2, null);
                    // Skip a missing channel (MikroTik returns "0.00") or a non-version line.
                    if (! is_string($version) || ! preg_match('/^\d+\.\d+/', $version) || (int) $version === 0) {
                        continue;
                    }
                    $out[] = [
                        'major' => $major,
                        'channel' => $channel,
                        'version' => $version,
                        'released_at' => is_numeric($ts) ? (int) $ts : null,
                    ];
                }
            }

            return $out;
        });
    }

    private function fetchNewest(int $major, string $channel): ?string
    {
        try {
            $res = Http::timeout(8)->get("{$this->base()}/NEWEST{$major}.{$channel}");

            return $res->successful() ? $res->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The .npk filename for a version + arch. RouterOS 7 orders it version-first, 6 arch-first.
     *
     * RouterOS 7 ships x86 AND CHR (which reports its architecture as `x86_64`) as one arch-LESS
     * package - there is no `routeros-<v>-x86.npk` or `-x86_64.npk`, only `routeros-<v>.npk`. Every
     * other architecture is suffixed as normal. This is why a CHR upgrade fetches a different file
     * than, say, an arm64 device.
     */
    public function packageFilename(string $version, string $arch): string
    {
        $major = (int) $version;

        if ($major >= 7) {
            return in_array($arch, ['x86', 'x86_64'], true)
                ? "routeros-{$version}.npk"
                : "routeros-{$version}-{$arch}.npk";
        }

        return "routeros-{$arch}-{$version}.npk";
    }

    /** Full MikroTik download URL for a version + arch. */
    public function packageUrl(string $version, string $arch): string
    {
        return "{$this->base()}/{$version}/{$this->packageFilename($version, $arch)}";
    }

    /** Byte size of the package on MikroTik if it exists (HEAD), else null. */
    public function remoteSize(string $version, string $arch): ?int
    {
        try {
            $res = Http::timeout(12)->head($this->packageUrl($version, $arch));
            if (! $res->successful()) {
                return null;
            }
            $len = $res->header('Content-Length');

            return is_numeric($len) ? (int) $len : 0;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+(\.\d+)?$/', $version) === 1;
    }

    public static function isValidArch(string $arch): bool
    {
        return in_array($arch, self::ARCHES, true);
    }
}
