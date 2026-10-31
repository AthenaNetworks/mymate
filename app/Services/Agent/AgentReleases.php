<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Agent binaries for remote upgrades, one per version + platform, cached under
 * storage/app/agent-releases/<version>/<os>_<arch>/. Fetched from the release that matches this
 * server (the published archives, checked against the release's SHA256SUMS), or dropped into that
 * folder by hand on an install with no internet access.
 */
class AgentReleases
{
    /** Platforms a release ships (agent/build.sh), as the agent reports them: GOOS/GOARCH. */
    public const PLATFORMS = ['linux/amd64', 'linux/arm64', 'linux/arm', 'darwin/amd64', 'darwin/arm64', 'windows/amd64', 'windows/arm64'];

    public static function validVersion(string $version): bool
    {
        return preg_match('/^v\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) === 1;
    }

    public static function validPlatform(string $platform): bool
    {
        return in_array($platform, self::PLATFORMS, true);
    }

    public function dir(string $version, string $platform): string
    {
        return storage_path('app/agent-releases/'.$version.'/'.str_replace('/', '_', $platform));
    }

    public function binaryPath(string $version, string $platform): string
    {
        return $this->dir($version, $platform).'/'.(str_starts_with($platform, 'windows/') ? 'mymate-agent.exe' : 'mymate-agent');
    }

    /**
     * The cached binary, without fetching anything (what the hub serves), or null.
     *
     * @return array{path:string, sha256:string, size:int}|null
     */
    public function cached(string $version, string $platform): ?array
    {
        if (! self::validVersion($version) || ! self::validPlatform($platform)) {
            return null;
        }
        $path = $this->binaryPath($version, $platform);
        if (! is_file($path)) {
            return null;
        }
        $sumFile = $path.'.sha256';
        $sum = is_file($sumFile) ? trim((string) file_get_contents($sumFile)) : '';
        if (strlen($sum) !== 64) {
            $sum = (string) hash_file('sha256', $path);
            @file_put_contents($sumFile, $sum);
        }

        return ['path' => $path, 'sha256' => $sum, 'size' => (int) filesize($path)];
    }

    /**
     * The binary for version + platform, fetching and verifying it first if it isn't cached.
     *
     * @return array{path:string, sha256:string, size:int}
     */
    public function ensure(string $version, string $platform): array
    {
        if (! self::validVersion($version) || ! self::validPlatform($platform)) {
            throw new RuntimeException("There's no agent build for {$version} on {$platform}.");
        }
        if ($hit = $this->cached($version, $platform)) {
            return $hit;
        }

        [$os, $arch] = explode('/', $platform);
        $archive = "mymate-agent_{$version}_{$os}_{$arch}.".($os === 'windows' ? 'zip' : 'tar.gz');
        $base = rtrim(str_replace('{version}', $version, (string) config('mymate.agents.release_url')), '/');

        $expected = null;
        foreach (preg_split('/\R/', $this->get("{$base}/SHA256SUMS")) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(?:\.\/)?(\S+)$/i', trim($line), $m) && $m[2] === $archive) {
                $expected = strtolower($m[1]);
            }
        }
        if ($expected === null) {
            throw new RuntimeException("The {$version} release has no {$archive}.");
        }

        $work = storage_path('app/agent-releases/.work-'.Str::random(12));
        File::ensureDirectoryExists($work);
        try {
            $file = "{$work}/{$archive}";
            file_put_contents($file, $this->get("{$base}/{$archive}"));
            if (! hash_equals($expected, (string) hash_file('sha256', $file))) {
                throw new RuntimeException("{$archive} doesn't match the release checksum.");
            }

            $this->extract($file, "{$work}/x", $os);
            $name = $os === 'windows' ? 'mymate-agent.exe' : 'mymate-agent';
            $found = collect(File::allFiles("{$work}/x"))->first(fn ($f) => $f->getFilename() === $name);
            if ($found === null) {
                throw new RuntimeException("{$archive} has no {$name} in it.");
            }

            File::ensureDirectoryExists($this->dir($version, $platform));
            $dest = $this->binaryPath($version, $platform);
            File::move($found->getPathname(), $dest);
            @chmod($dest, 0o755);
        } finally {
            File::deleteDirectory($work);
        }

        return $this->cached($version, $platform) ?? throw new RuntimeException('The agent binary went missing after download.');
    }

    private function get(string $url): string
    {
        $res = Http::timeout(120)->connectTimeout(15)->get($url);
        if (! $res->successful()) {
            throw new RuntimeException("Couldn't download {$url} (HTTP {$res->status()}).");
        }

        return $res->body();
    }

    private function extract(string $file, string $into, string $os): void
    {
        File::ensureDirectoryExists($into);
        if ($os === 'windows') {
            $zip = new ZipArchive;
            if ($zip->open($file) !== true || ! $zip->extractTo($into)) {
                throw new RuntimeException('Could not unpack the agent archive.');
            }
            $zip->close();

            return;
        }
        $p = new Process(['tar', '-xzf', $file, '-C', $into, '--no-same-owner']);
        $p->setTimeout(60)->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Could not unpack the agent archive: '.trim($p->getErrorOutput()));
        }
    }
}
