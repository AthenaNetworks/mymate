<?php

namespace Tests\Support;

use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsTarget;
use Throwable;

/**
 * In-memory RouterOsClient for tests - no hardware. `open()` hands back a
 * FakeRouterOsConnection with the scripted replies, or throws `$failOpenWith`
 * to simulate a filtered port / auth failure.
 */
class FakeRouterOsClient implements RouterOsClient
{
    /** Connections handed out by open(), in order - inspect their recorded queries. */
    public array $opened = [];

    /** Targets passed to open(), in order. */
    public array $targets = [];

    /** @param array<string, list<array<string, string>>> $replies */
    public function __construct(
        public array $replies = [],
        public ?Throwable $failOpenWith = null,
    ) {}

    public function open(RouterOsTarget $target): RouterOsConnection
    {
        $this->targets[] = $target;

        if ($this->failOpenWith !== null) {
            throw $this->failOpenWith;
        }

        $conn = new FakeRouterOsConnection($this->replies);
        $this->opened[] = $conn;

        return $conn;
    }
}
