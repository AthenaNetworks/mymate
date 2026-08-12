<?php

namespace Tests\Support;

use App\Services\RouterOs\RouterOsConnection;
use Throwable;

/**
 * In-memory RouterOsConnection - returns canned replies keyed by command.
 * Set `$replies['/interface/print'] = [[...], ...]` to script a device. A reply that is a
 * Throwable is thrown instead of returned, so a test can simulate one command failing.
 */
class FakeRouterOsConnection implements RouterOsConnection
{
    /** Every query() call recorded in order: [['command' => ..., 'params' => [...]], ...]. */
    public array $queries = [];

    public bool $closed = false;

    /** @param array<string, list<array<string, string>>|Throwable> $replies */
    public function __construct(public array $replies = []) {}

    public function query(string $command, array $params = []): array
    {
        $this->queries[] = ['command' => $command, 'params' => $params];

        $reply = $this->replies[$command] ?? [];
        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /** Command strings issued, in order - handy for asserting an upgrade sequence. */
    public function commands(): array
    {
        return array_column($this->queries, 'command');
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
