<?php

namespace App\Services\RouterOs;

use RouterOS\Client;
use RouterOS\Query;
use Throwable;

/**
 * RouterOsConnection backed by evilfreelancer/routeros-api-php. Translates our
 * (command, params) calls into the package's Query objects and normalises lib
 * failures into RouterOsClientException.
 */
class EvilFreelancerRouterOsConnection implements RouterOsConnection
{
    public function __construct(private ?Client $client) {}

    public function query(string $command, array $params = []): array
    {
        if ($this->client === null) {
            throw new RouterOsClientException('RouterOS connection is closed.');
        }

        try {
            $query = new Query($command);
            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }

            $rows = $this->client->query($query)->read();

            // Keep only the associative reply rows (drop any non-array sentinels).
            return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
        } catch (Throwable $e) {
            throw new RouterOsClientException('RouterOS query failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function close(): void
    {
        // Dropping the only reference lets the package close the socket on destruct.
        $this->client = null;
    }
}
