<?php

namespace App\Services\RouterOs;

/**
 * Thin abstraction over the RouterOS binary API so callers depend on this, not the
 * (unmaintained) package. Keeps the driver fakeable in tests and lets us swap the
 * transport later (e.g. the RouterOS v7 REST API) behind the same interface.
 *
 * Implementations MUST use a short connect timeout so a filtered/black-holing port
 * fails fast instead of wedging a worker.
 */
interface RouterOsClient
{
    /**
     * Open + authenticate a session to the device.
     *
     * @throws RouterOsClientException on connect / auth failure.
     */
    public function open(RouterOsTarget $target): RouterOsConnection;
}
