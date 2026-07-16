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
 * A host is probed for its POLL credential (SNMP first - connectionless GET, no auth state,
 * no lockout risk - then the RouterOS API) AND, independently, for an SSH credential (used for
 * config backups). Both are returned so promotion can link the poll cred and the backup cred
 * to the device. The pool is read **read-only**; decrypted secrets live only in this call and
 * are NEVER logged.
 *
 * Safety (NFR-10): the underlying clients use short, fail-fast timeouts, and repeated login
 * attempts (RouterOS, SSH) are spaced by `attemptDelayMs` to stay lockout-aware when several
 * credentials are tried against one host.
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
        // Try EVERY credential type so we can tag the host with all that worked (a MikroTik can
        // answer both SNMP and the RouterOS API). SNMP and RouterOS each yield a poll match; SSH
        // yields a backup match. (Empty per-type pools return instantly, so this stays cheap when
        // the operator only has one credential type.)
        $snmp = $this->probeSnmp($ip, $credentials->where('type', PollMethod::Snmp->value));
        $routerOs = $this->probeRouterOs($ip, $credentials->where('type', PollMethod::RouterOs->value));
        $sshCredentialId = $this->probeSsh($ip, $credentials->where('type', 'ssh'));

        // Primary poll credential: SNMP preferred (connectionless, no lockout risk), else RouterOS.
        $poll = $snmp->identified() ? $snmp : $routerOs;

        $matchedCredentialIds = array_values(array_filter(
            [$snmp->credentialId, $routerOs->credentialId, $sshCredentialId],
            static fn (?int $id): bool => $id !== null,
        ));

        return new ProbeResult(
            method: $poll->method,
            sysname: $snmp->sysname ?? $routerOs->sysname,
            credentialId: $poll->credentialId,
            sshCredentialId: $sshCredentialId,
            matchedCredentialIds: $matchedCredentialIds,
        );
    }

    /**
     * Find an SSH credential that authenticates on port 22 (key first, then password). Returns
     * its id, or null if none work. Bounded + spaced like the RouterOS trial so it stays
     * lockout-aware.
     *
     * @param  Collection<int, Credential>  $creds
     */
    private function probeSsh(string $ip, Collection $creds): ?int
    {
        $timeout = max(1, (int) config('mymate.discovery.ssh_probe_timeout_s', 4));
        $first = true;

        foreach ($creds as $cred) {
            if (! $cred->username) {
                continue;
            }
            if (! $first && $this->attemptDelayMs > 0) {
                usleep($this->attemptDelayMs * 1000);
            }
            $first = false;

            try {
                $ssh = new \phpseclib3\Net\SSH2($ip, 22, $timeout);
                $ssh->setTimeout($timeout);

                $ok = false;
                if ($cred->private_key) {
                    try {
                        $key = \phpseclib3\Crypt\PublicKeyLoader::load((string) $cred->private_key, (string) ($cred->password ?? ''));
                        $ok = $ssh->login((string) $cred->username, $key);
                    } catch (\Throwable) {
                        $ok = false; // unloadable/mismatched key - fall through to password below
                    }
                }
                if (! $ok && $cred->password) {
                    $ok = $ssh->login((string) $cred->username, (string) $cred->password);
                }
                $ssh->disconnect();

                if ($ok) {
                    return (int) $cred->id;
                }
            } catch (\Throwable) {
                // Port 22 filtered/closed, connect timeout, or protocol error - try the next cred.
            }
        }

        return null;
    }

    /** @param  Collection<int, Credential>  $creds */
    private function probeSnmp(string $ip, Collection $creds): ProbeResult
    {
        $sysNameOid = (string) config('mymate.snmp.oids.sys_name', '.1.3.6.1.2.1.1.5.0');

        foreach ($creds as $cred) {
            $community = \App\Services\Snmp\SnmpCredential::fromCredential($cred);
            if (! $community->isUsable()) {
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
