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
        $skipped = 0;   // responders that answered ping but matched no credential at all
        $deferred = 0;  // new responders left un-probed this run because the time budget ran out

        if ($reachable !== []) {
            // Read-only try-pool - secrets decrypted only inside the prober, never logged.
            $credentials = Credential::all();
            // IPs that are already devices (skip) and existing candidates (update, never dup).
            $deviceIps = Device::pluck('mgmt_ip')->flip();
            $existing = DiscoveryCandidate::whereIn('ip', $reachable)->get()->keyBy('ip');

            // Probing tries SNMP + RouterOS + SSH per host, which is slow; cap the wall-clock so
            // the job can't blow past its queue timeout. Un-probed responders are just retried on
            // the next scan (found candidates are skipped, so successive sweeps make progress).
            $budget = (int) config('mymate.discovery.scan_probe_budget_s', 45);
            $deadline = $budget > 0 ? microtime(true) + $budget : null;

            foreach ($reachable as $ip) {
                if ($deviceIps->has($ip)) {
                    continue;
                }

                $candidate = $existing->get($ip);
                if ($candidate !== null) {
                    // Seen again: bump last_seen. Never re-trial creds, never change status
                    // (so an ignored candidate stays ignored). Cheap - not subject to the budget.
                    $candidate->update(['last_seen' => $now]);

                    continue;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    $deferred++; // out of time - leave this new host for the next sweep
                    continue;
                }

                $probe = $this->prober->probe($ip, $credentials);

                // Queue any host we linked at least one credential to - a poll credential
                // (SNMP/RouterOS) and/or an SSH credential for backups. A host that answers
                // ping but matches nothing isn't actionable, so it stays out of the queue.
                if (! $probe->matchedAny()) {
                    $skipped++;

                    continue;
                }

                DiscoveryCandidate::create([
                    'ip' => $ip,
                    'status' => DiscoveryStatus::New,
                    'sysname' => $probe->sysname,
                    'detected_method' => $probe->method, // null when only SSH matched (ping-only device)
                    'matched_credential_id' => $probe->credentialId,
                    'matched_ssh_credential_id' => $probe->sshCredentialId,
                    'matched_credential_ids' => $probe->matchedCredentialIds, // all that worked (tags)
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
            'unidentified_skipped' => $skipped,  // pinged but no credential matched
            'deferred_to_next_run' => $deferred, // new responders un-probed (time budget) - not silent
        ]);

        return $new;
    }
}
