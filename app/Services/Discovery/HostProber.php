<?php

namespace App\Services\Discovery;

use App\Enums\PollMethod;
use App\Models\Credential;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use Illuminate\Support\Collection;

/**
 * Identifies one responding host by trying the read-only credential pool.
 *
 * Order: SNMP first (connectionless GET - no auth state, no lockout risk), then the
 * RouterOS API (a real login). Returns the first credential that works. The pool is
 * read **read-only**; decrypted secrets live only in this call and are NEVER logged.
 *
 * Safety (NFR-10): the underlying SnmpClient/RouterOsClient use short, fail-fast
 * timeouts, and RouterOS login attempts are spaced by `attemptDelayMs` to stay
 * lockout-aware when several routeros credentials are tried against one host.
 */
class HostProber
{
    public function __construct(
        private SnmpClient $snmp,
        private RouterOsClient $routerOs,
        private int $attemptDelayMs = 200,
    ) {}

    /**
     * @param  Collection<int, Credential>  $credentials  the read-only try-pool
     */
    public function probe(string $ip, Collection $credentials): ProbeResult
    {
        $snmp = $this->probeSnmp($ip, $credentials->where('type', PollMethod::Snmp->value));
        if ($snmp->identified()) {
            return $snmp;
        }

        return $this->probeRouterOs($ip, $credentials->where('type', PollMethod::RouterOs->value));
    }

    /** @param  Collection<int, Credential>  $creds */
    private function probeSnmp(string $ip, Collection $creds): ProbeResult
    {
        $sysNameOid = (string) config('mymate.snmp.oids.sys_name', '.1.3.6.1.2.1.1.5.0');

        foreach ($creds as $cred) {
            $community = (string) $cred->snmp_community;
            if ($community === '') {
                continue;
            }
            try {
                $result = $this->snmp->get($ip, $community, [$sysNameOid]);
                if ($result === []) {
                    continue; // responded with nothing for this community - not a match.
                }

                return new ProbeResult(PollMethod::Snmp, $this->firstValue($result), (int) $cred->id);
            } catch (SnmpClientException) {
                // Wrong community / filtered / no response - try the next credential.
            }
        }

        return ProbeResult::none();
    }

    /** @param  Collection<int, Credential>  $creds */
    private function probeRouterOs(string $ip, Collection $creds): ProbeResult
    {
        $timeout = max(1, (int) config('mymate.routeros.timeout', 3));
        $first = true;

        foreach ($creds as $cred) {
            if (! $cred->username) {
                continue;
            }

            // Space repeated logins so the trial can't look like a brute force / trip a lockout.
            if (! $first && $this->attemptDelayMs > 0) {
                usleep($this->attemptDelayMs * 1000);
            }
            $first = false;

            $port = $cred->api_port ?: 8728;
            try {
                $conn = $this->routerOs->open(new RouterOsTarget(
                    host: $ip,
                    port: $port,
                    username: (string) $cred->username,
                    password: (string) $cred->password,
                    timeout: $timeout,
                    ssl: $port === 8729,
                ));

                try {
                    $rows = $conn->query('/system/identity/print');
                } finally {
                    $conn->close();
                }

                $sysname = $rows[0]['name'] ?? null;

                return new ProbeResult(PollMethod::RouterOs, $sysname !== '' ? $sysname : null, (int) $cred->id);
            } catch (RouterOsClientException) {
                // Wrong creds / filtered API port - try the next credential.
            }
        }

        return ProbeResult::none();
    }

    /** First scalar value of an SNMP result, or null if empty/blank. */
    private function firstValue(array $result): ?string
    {
        foreach ($result as $value) {
            $value = (string) $value;

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
