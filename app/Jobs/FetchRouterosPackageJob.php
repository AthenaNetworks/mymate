<?php

namespace App\Jobs;

use App\Actions\Upgrade\FetchRouterosPackage;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Download + cache one RouterOS package off the request path. One in flight per version+arch.
 */
class FetchRouterosPackageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $version, public string $arch, public ?string $channel = null)
    {
        $this->onQueue('default');
    }

    public function handle(FetchRouterosPackage $fetch): void
    {
        $fetch->fetch($this->version, $this->arch, $this->channel);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("routeros-pkg:{$this->version}:{$this->arch}"))->dontRelease()->expireAfter(700)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('routeros package: job failed', ['version' => $this->version, 'arch' => $this->arch, 'error' => $e->getMessage()]);
    }
}
