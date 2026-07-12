<?php

namespace App\Services\RouterOs;

use App\Enums\PollMethod;
use App\Models\Device;

/**
 * Connection details for one RouterOS device. Holds the decrypted password in
 * memory only for the duration of a poll - never log this object.
 */
final readonly class RouterOsTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public int $timeout,
        public bool $ssl,
    ) {}

    /**
     * Build a target from a device's RouterOS credential (the one place this
     * resolution lives - used by the throughput driver and the upgrade path).
     * Port 8729 => TLS. Decrypts the credential in memory only.
     *
     * @throws RouterOsClientException when the device has no usable RouterOS credential.
     */
    public static function fromDevice(Device $device): self
    {
        $device->loadMissing('credential');
        $credential = $device->credential;

        if ($credential === null
            || $credential->type !== PollMethod::RouterOs->value
            || ! $credential->username) {
            throw new RouterOsClientException("Device {$device->id} ({$device->name}) has no RouterOS credential.");
        }

        $port = $credential->api_port ?: 8728;

        return new self(
            host: $device->mgmt_ip,
            port: $port,
            username: (string) $credential->username,
            password: (string) $credential->password,
            timeout: max(1, (int) config('mymate.routeros.timeout', 3)),
            ssl: $port === 8729,
        );
    }
}
