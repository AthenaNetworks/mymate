<?php

namespace App\Services\RouterOs;

use RouterOS\Client;
use Throwable;

/**
 * RouterOsClient backed by evilfreelancer/routeros-api-php. Opening the Client
 * connects + authenticates immediately; `attempts=1` + a short `timeout` make a
 * filtered/black-holing port (e.g. BDR1:8728) fail fast instead of hanging.
 */
class EvilFreelancerRouterOsClient implements RouterOsClient
{
    public function open(RouterOsTarget $target): RouterOsConnection
    {
        try {
            $client = new Client([
                'host' => $target->host,
                'user' => $target->username,
                'pass' => $target->password,
                'port' => $target->port,
                'ssl' => $target->ssl,
                'timeout' => $target->timeout,
                'attempts' => 1,
            ]);
        } catch (Throwable $e) {
            // Message carries host + transport error only - never the credentials.
            throw new RouterOsClientException("RouterOS connect failed for {$target->host}: ".$e->getMessage(), 0, $e);
        }

        return new EvilFreelancerRouterOsConnection($client);
    }
}
