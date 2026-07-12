<?php

namespace App\Actions\Discovery;

use App\Enums\DiscoveryStatus;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\Subnet;
use App\Services\Discovery\HostProber;
use App\Services\Discovery\Scanner;
use App\Services\Ping\Pinger;
use App\Support\EngineLog;

/**
 * Sweep one subnet for new devices. CIDR -> batched fping sweep -> for each
 * responder that isn't already a device, probe the read-only credential pool and
 * queue it as a `discovery_candidate` (review queue - never auto-added).
 *
 * Safety (NFR-10): host count is capped by Scanner; only **genuinely new** IPs are
 * credential-probed (a re-scan just bumps `last_seen`), which keeps credential
 * trials minimal and never resurrects an ignored candidate. Returns new-candidate count.
 */
class ScanSubnet
{
    public function __construct(
        private Scanner $scanner,
        private Pinger $pinger,
        private HostProber $prober,
    ) {}

    public function __invoke(Subnet $subnet): int
    {
        $hosts = $this->scanner->hosts($subnet->cidr);
        if ($hosts === []) {
            EngineLog::warning('discovery: invalid or empty subnet', [
                'subnet_id' => $subnet->id,
                'cidr' => $subnet->cidr,
            ]);
            $subnet->update(['last_scanned_at' => now()]);

            return 0;
        }

        $usable = Scanner::usableCount($subnet->cidr);
        if (count($hosts) < $usable) {
            // No silent truncation: say what we dropped.
            EngineLog::warning('discovery: subnet host count capped', [
                'subnet_id' => $subnet->id,
                'cidr' => $subnet->cidr,
                'usable' => $usable,
                'scanned' => count($hosts),
            ]);
        }

        $reachable = $this->pinger->reachable($hosts);
        $now = now();

        $new = 0;
        $skipped = 0; // responders we couldn't identify via SNMP/RouterOS

        if ($reachable !== []) {
            // Read-only try-pool - secrets decrypted only inside the prober, never logged.
            $credentials = Credential::all();
            // IPs that are already devices (skip) and existing candidates (update, never dup).
            $deviceIps = Device::pluck('mgmt_ip')->flip();
            $existing = DiscoveryCandidate::whereIn('ip', $reachable)->get()->keyBy('ip');

            foreach ($reachable as $ip) {
                if ($deviceIps->has($ip)) {
                    continue;
                }

                $candidate = $existing->get($ip);
                if ($candidate !== null) {
                    // Seen again: bump last_seen. Never re-trial creds, never change status
                    // (so an ignored candidate stays ignored).
                    $candidate->update(['last_seen' => $now]);

                    continue;
                }

                $probe = $this->prober->probe($ip, $credentials);

                // Only queue hosts we positively identified via SNMP or RouterOS (#7).
                // A host that answers ping but matches no credential isn't actionable
                // (there's nothing to poll), so it stays out of the review queue.
                if (! $probe->identified()) {
                    $skipped++;

                    continue;
                }

                DiscoveryCandidate::create([
                    'ip' => $ip,
                    'status' => DiscoveryStatus::New,
                    'sysname' => $probe->sysname,
                    'detected_method' => $probe->method,
                    'matched_credential_id' => $probe->credentialId,
                    'first_seen' => $now,
                    'last_seen' => $now,
                ]);
                $new++;
            }
        }

        $subnet->update(['last_scanned_at' => $now]);

        EngineLog::info('discovery: subnet scanned', [
            'subnet_id' => $subnet->id,
            'cidr' => $subnet->cidr,
            'hosts' => count($hosts),
            'reachable' => count($reachable),
            'new_candidates' => $new,
            'unidentified_skipped' => $skipped, // pinged but no SNMP/RouterOS match
        ]);

        return $new;
    }
}
