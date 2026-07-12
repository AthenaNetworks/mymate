<?php

namespace App\Services\RouterOs;

/**
 * An open RouterOS API session (one device). Returned by RouterOsClient::open;
 * close() when done. Callers depend on this, not the underlying package.
 */
interface RouterOsConnection
{
    /**
     * Run one command and return its replies as plain associative rows
     * (e.g. `/interface/print` -> list of {.id, name, type, ...}).
     *
     * @param  array<string, string>  $params  attributes, e.g. ['interface' => 'ether1', 'once' => '']
     * @return list<array<string, string>>
     *
     * @throws RouterOsClientException
     */
    public function query(string $command, array $params = []): array;

    public function close(): void;
}
