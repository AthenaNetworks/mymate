<?php

namespace App\Jobs;

use App\Actions\Devices\FetchMikrotikIcon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Fetch + cache a MikroTik model's product image off the request path (the controller
 * 404s on a miss and dispatches this). One in flight per model. tries=1 - a failed lookup
 * is negative-cached by the controller, so no retry storm on an unresolvable slug.
 */
class FetchDeviceIconJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $model)
    {
        $this->onQueue('default');
    }

    public function handle(FetchMikrotikIcon $icons): void
    {
        $icons->fetch($this->model);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('device-icon:'.FetchMikrotikIcon::slug($this->model)))->dontRelease()->expireAfter(120)];
    }
}
