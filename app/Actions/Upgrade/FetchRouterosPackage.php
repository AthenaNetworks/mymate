<?php

namespace App\Actions\Upgrade;

use App\Models\RouterosPackage;
use App\Services\Upgrade\RouterosReleases;
use App\Support\EngineLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Download + cache one RouterOS package (.npk) for a package name + version + arch from MikroTik. Idempotent:
 * a package already `ready` with its file present is returned as-is. Streams to disk so a
 * ~15 MB image never sits in memory. Marks the row ready/failed so the UI + upgrade flow can
 * see the state.
 */
class FetchRouterosPackage
{
    private const DIR = 'routeros-packages';

    public function __construct(private RouterosReleases $releases) {}

    /** Find-or-create the package row for this package+version+arch (does not download). */
    public function ensureRow(string $version, string $arch, ?string $channel = null, string $name = 'routeros'): RouterosPackage
    {
        return RouterosPackage::firstOrCreate(
            ['name' => $name, 'version' => $version, 'arch' => $arch],
            ['channel' => $channel, 'status' => 'pending', 'token' => Str::random(40)],
        );
    }

    public function fetch(string $version, string $arch, ?string $channel = null, string $name = 'routeros'): RouterosPackage
    {
        $pkg = $this->ensureRow($version, $arch, $channel, $name);

        if ($pkg->isReady() && $pkg->path && Storage::disk('local')->exists($pkg->path)) {
            return $pkg; // already cached
        }

        $filename = $this->releases->packageFilename($version, $arch, $name);
        $path = self::DIR."/{$filename}";
        $abs = Storage::disk('local')->path($path);
        @mkdir(dirname($abs), 0775, true);

        try {
            $res = Http::timeout(300)->withHeaders(['User-Agent' => 'my-mate'])
                ->sink($abs)->get($this->releases->packageUrl($version, $arch, $name));

            if (! $res->successful()) {
                @unlink($abs);
                throw new \RuntimeException("MikroTik returned HTTP {$res->status()} for {$filename}");
            }

            $size = is_file($abs) ? (int) filesize($abs) : 0;
            if ($size < 1024) { // an error page, not a real .npk
                @unlink($abs);
                throw new \RuntimeException("Downloaded {$filename} is too small ({$size} bytes)");
            }

            $pkg->forceFill(['status' => 'ready', 'size_bytes' => $size, 'path' => $path, 'error' => null, 'fetched_at' => now()])->save();
        } catch (\Throwable $e) {
            $pkg->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            EngineLog::warning('routeros package: fetch failed', ['name' => $name, 'version' => $version, 'arch' => $arch, 'error' => $e->getMessage()]);
        }

        return $pkg->refresh();
    }

    /** Delete a cached package's file + row. */
    public function delete(RouterosPackage $pkg): void
    {
        if ($pkg->path) {
            Storage::disk('local')->delete($pkg->path);
        }
        $pkg->delete();
    }
}
