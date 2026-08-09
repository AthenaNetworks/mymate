<?php

return [

    // Where engine logs (pollers/discovery/loop) go. Point at 'stack'/'stderr'/etc.
    // to reroute without touching code. See App\Support\EngineLog + config/logging.php.
    'log_channel' => env('MYMATE_LOG_CHANNEL', 'mymate'),

    // Up/down loop (fping). interval in seconds; timeout in ms.
    'ping' => [
        // Path to the fping >=5.5 binary. Null = auto-detect (common sbin/bin locations, then
        // PATH). Set explicitly if fping lives somewhere unusual. Resolving by absolute path
        // matters because php-fpm (web requests) often runs with a PATH that omits
        // /usr/local/sbin, where the from-source fping is installed.
        'fping' => env('MYMATE_FPING_PATH'),
        // Source address to ping FROM (fping -S). Empty = the OS default route. Set this to a
        // WAN/VRF-bound local address to test reachability along a specific path (GitHub #11).
        'source' => env('MYMATE_PING_SOURCE', ''),
        'interval' => (int) env('MYMATE_PING_INTERVAL', 5),
        'timeout_ms' => (int) env('MYMATE_PING_TIMEOUT_MS', 500),
        'retries' => (int) env('MYMATE_PING_RETRIES', 1),
        // fping -i: ms between successive targets. Null keeps fping's own default (~10ms),
        // which paces sends so hard that a big fleet takes minutes and trips the process
        // timeout. Set a small value (e.g. 1) on large installs - it sweeps tens of thousands
        // of hosts in seconds and is measurably more accurate (fewer late replies missed).
        'interval_ms' => env('MYMATE_PING_INTERVAL_MS') !== null ? (int) env('MYMATE_PING_INTERVAL_MS') : null,
        // Hard wall-clock cap (s) on a single fping process. A sweep that can't finish in time
        // is killed and the job fails; raise it in step with fleet size / shard size.
        'process_timeout' => (int) env('MYMATE_PING_PROCESS_TIMEOUT', 30),
        // Shard the up/down sweep into N parallel ping jobs by crc32(device_id) % shards, so no
        // single fping has to cover the whole fleet. 1 = one sweep (small installs). Raise it
        // with the fleet AND raise the ping worker count (MYMATE_PING_PROCESSES) to match.
        'shards' => (int) env('MYMATE_PING_SHARDS', 1),
        // Probes per host per sweep. >1 gives a real per-sweep loss % and jitter (min/max
        // RTT spread) for the latency graphs; 1 is lightest (loss is then 0/100 per sweep,
        // still averaged into a real % on read). fping sends them in parallel.
        'count' => (int) env('MYMATE_PING_COUNT', 3),
        // Gap between the per-host probes (ms) when count > 1 - keeps a multi-probe sweep snappy.
        'period_ms' => (int) env('MYMATE_PING_PERIOD_MS', 300),
        // How often (s) to persist a latency/loss history sample + refresh the live rtt/loss
        // columns. The up/down status flip still happens every sweep; only the trend write is
        // throttled to keep the fast sweep cheap.
        'history_interval' => (int) env('MYMATE_PING_HISTORY_INTERVAL', 60),
        // Consecutive missed sweeps before a device flips to `down`. 1 = flip on the first miss
        // (original behaviour). Higher tolerates transient packet loss - important at scale,
        // where a device that drops one reply shouldn't alarm. Recovery is always immediate
        // (one reply -> up). At the default 5s interval, 3 = ~15s of solid misses before down.
        'fail_threshold' => max(1, (int) env('MYMATE_PING_FAIL_THRESHOLD', 3)),
    ],

    // Live MTR traces (the device inspector's Trace button).
    'trace' => [
        // Path to the mtr binary. Null = auto-detect (common bin/sbin locations, then PATH).
        // Set explicitly if mtr lives somewhere unusual.
        'mtr' => env('MYMATE_MTR_PATH'),
    ],

    // The Tools page (ping, trace, subnet sweep, port scan, subnet calc, bgp.tools lookup).
    // Every tool streams its output into Redis for the browser to poll, same as a live trace.
    'tools' => [
        // Largest subnet a single sweep will enumerate, as a prefix length. /22 = 1024 hosts.
        // Anything bigger is rejected up front - a sweep has to finish inside the job timeout,
        // and nobody wants one operator kicking off a /8 ping storm from the poller box.
        'max_sweep_hosts' => (int) env('MYMATE_TOOLS_MAX_SWEEP_HOSTS', 1024),
        // Per-connection timeout (ms) for the TCP connect scan (port map + optional sweep ports).
        // A refused port answers instantly; this only bounds how long a filtered/dropped port
        // is waited on before it's called closed.
        'connect_timeout_ms' => (int) env('MYMATE_TOOLS_CONNECT_TIMEOUT_MS', 700),
        // How many TCP connects a scan has in flight at once. Higher finishes a wide port list
        // faster but opens that many sockets from this box; keep it civil on a shared server.
        'connect_concurrency' => (int) env('MYMATE_TOOLS_CONNECT_CONCURRENCY', 64),
        // Hard cap on how many ports one port-scan request may probe, so a caller can't ask for
        // all 65535 and pin the box. The "common ports" default list is well under this.
        'max_ports' => (int) env('MYMATE_TOOLS_MAX_PORTS', 1024),
        // NetBIOS node-status lookups during a sweep (UDP 137). Off = skip them (some networks
        // firewall 137 and the per-host wait just adds latency for nothing).
        'netbios' => (bool) env('MYMATE_TOOLS_NETBIOS', true),
        'netbios_timeout_ms' => (int) env('MYMATE_TOOLS_NETBIOS_TIMEOUT_MS', 700),
        // bgp.tools whois lookup (their port-43 interface). Host/port rarely need changing.
        'bgp' => [
            'host' => env('MYMATE_TOOLS_BGP_HOST', 'bgp.tools'),
            'port' => (int) env('MYMATE_TOOLS_BGP_PORT', 43),
            'timeout' => (int) env('MYMATE_TOOLS_BGP_TIMEOUT', 6),
            // How long (minutes) to cache a lookup. bgp.tools ask that their data not be hammered;
            // the same IP/ASN rarely changes AS within an hour.
            'cache_minutes' => (int) env('MYMATE_TOOLS_BGP_CACHE_MINUTES', 60),
        ],
    ],

    // Interface throughput loop. intervals in seconds.
    'poll' => [
        'interval' => (int) env('MYMATE_POLL_INTERVAL', 12),
        // How often the loop re-runs interface discovery (names/capacity change rarely).
        'discover_interval' => (int) env('MYMATE_DISCOVER_INTERVAL', 600),

        // Scale-out: throughput work is sharded into N batch jobs by
        // crc32(device_id) % shards, each guarded by a per-shard overlap lock.
        // Tune UP for large fleets - more shards = smaller batches = more parallel
        // poll workers, AND smaller per-shard broadcasts (see `broadcast` below -
        // each shard's linked-interface total is what actually gets chunked into
        // WS messages). Rule of thumb: shards ~ devices x p95_poll_seconds / interval.
        // If `poll: batch complete` log lines show `broadcast_bytes` regularly
        // approaching `broadcast.max_bytes_per_event`, or a `poll: device broadcast
        // frame exceeds byte budget` warning appears, that's the signal to add more
        // shards (spreads links across more, smaller batches) - not just to grow
        // the byte/interface caps below.
        'shards' => (int) env('MYMATE_POLL_SHARDS', 16),

        'broadcast' => [
            'enabled' => (bool) env('MYMATE_BROADCAST_UTIL', true),
            // Two independent caps on one coalesced util event - whichever is hit
            // first ends the current chunk. Interfaces are already narrowed to
            // link-bound ones only ( / P7's "narrows broadcasts to
            // link-bound interfaces"), but neither cap here is redundant:
            'max_interfaces_per_event' => (int) env('MYMATE_BROADCAST_MAX_IFACES', 500),
            // ...is a cheap sanity bound, but doesn't actually protect against
            // Reverb's `max_message_size`/`max_request_size` (config/reverb.php,
            // both default 10,000 bytes) - measured ~166 bytes/interface in
            // production, so 500 interfaces (~83,000 bytes) is 8x over the real
            // limit. This byte cap is the real protection, sized well under half
            // of Reverb's 10,000-byte default: Pusher's protocol re-embeds our
            // payload as an *escaped JSON string* inside its own envelope, so the
            // raw json_encode() size of our array understates the actual wire size
            // Reverb measures - the generous margin absorbs that escaping overhead
            // plus the envelope itself without needing to model Pusher's internal
            // wire format precisely (which would be fragile against SDK changes).
            'max_bytes_per_event' => (int) env('MYMATE_BROADCAST_MAX_BYTES', 6000),
        ],
    ],

    // SNMP throughput driver. Short timeout so a filtered port fails fast.
    'snmp' => [
        'timeout_us' => (int) env('MYMATE_SNMP_TIMEOUT_US', 1_000_000), // 1s
        'retries' => (int) env('MYMATE_SNMP_RETRIES', 1),
        // Numeric OIDs (no MIBs needed). ifXTable = 64-bit HC counters + ifHighSpeed (Mbps).
        'oids' => [
            'if_descr' => '.1.3.6.1.2.1.2.2.1.2',
            'if_name' => '.1.3.6.1.2.1.31.1.1.1.1',
            'if_alias' => '.1.3.6.1.2.1.31.1.1.1.18', // operator-set port description (ifAlias)
            'if_high_speed' => '.1.3.6.1.2.1.31.1.1.1.15',
            'if_hc_in_octets' => '.1.3.6.1.2.1.31.1.1.1.6',
            'if_hc_out_octets' => '.1.3.6.1.2.1.31.1.1.1.10',
            'if_oper_status' => '.1.3.6.1.2.1.2.2.1.8', // ifOperStatus (1=up) - per-port up/down

            // system group - used by discovery to identify a responder and
            // by CaptureDeviceFacts for vendor/uptime/type.
            'sys_name' => '.1.3.6.1.2.1.1.5.0',
            'sys_location' => '.1.3.6.1.2.1.1.6.0', // operator-set location (may carry [lat, lng])
            'sys_object_id' => '.1.3.6.1.2.1.1.2.0',
            'sys_descr' => '.1.3.6.1.2.1.1.1.0',
            'sys_uptime' => '.1.3.6.1.2.1.1.3.0',
            // Hardware inventory (CaptureDeviceFacts): ENTITY-MIB model/serial (cross-vendor)
            // + host-resources total RAM.
            'ent_model' => '.1.3.6.1.2.1.47.1.1.1.1.13',  // entPhysicalModelName (walk; chassis row)
            'ent_serial' => '.1.3.6.1.2.1.47.1.1.1.1.11', // entPhysicalSerialNum (walk; chassis row)
            'hr_memory' => '.1.3.6.1.2.1.25.2.2.0',        // hrMemorySize (KWords/KB of physical RAM)
        ],
    ],

    // Links. When neither end of a link reports an interface speed (e.g. RouterOS returns 0
    // for ifSpeed/ifHighSpeed over SNMP), fall back to this effective speed so the link still
    // colours by load instead of staying neutral. A per-link bandwidth override always wins.
    'links' => [
        'default_speed_mbps' => (int) env('MYMATE_LINK_DEFAULT_SPEED_MBPS', 1000),
    ],

    // Geographic map overlay (GitHub #11). Raster tiles are loaded straight from the tile
    // provider by the browser, so its host is added to the CSP img-src (SecurityHeaders).
    'map' => [
        // Leaflet raster tile URL template ({z}/{x}/{y}). Empty string disables the geo overlay.
        'tile_url' => env('MYMATE_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        // Attribution the provider requires (shown on the map).
        'tile_attribution' => env('MYMATE_MAP_TILE_ATTRIBUTION', '(c) OpenStreetMap contributors'),
        // Space-separated hosts allowed in the CSP img-src so the browser can fetch tiles.
        'tile_csp_hosts' => env('MYMATE_MAP_TILE_CSP_HOSTS', 'https://*.tile.openstreetmap.org https://tile.openstreetmap.org'),
        // Address -> lat/lng geocoder, proxied server-side (fixed trusted host, like the
        // update check). Empty disables address lookup; drag-drop still works.
        'geocoder_url' => env('MYMATE_MAP_GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'),
    ],

    // Update check: compare this install's version against the latest GitHub release so
    // the console can flag when a newer version is out. The version comes from
    // MYMATE_VERSION (stamped by the packaged build) or the repo-root VERSION file.
    'update' => [
        'enabled' => (bool) env('MYMATE_UPDATE_CHECK', true),
        'repo' => env('MYMATE_UPDATE_REPO', 'AthenaNetworks/mymate'),
        'version' => env('MYMATE_VERSION'),
        // How long to cache the latest-release lookup (hours) - GitHub isn't hit per request.
        'cache_hours' => (int) env('MYMATE_UPDATE_CACHE_HOURS', 12),
    ],

    // Device resource metrics - CPU / memory / temperature. Polled on their own
    // (slower) cadence than throughput; latest values cached on the device row and
    // trend samples appended to device_metric_samples. Off by env if you don't want it.
    'device_metrics' => [
        'enabled' => (bool) env('MYMATE_DEVICE_METRICS', true),
        // Seconds between metric polls. Slower than throughput - cpu/mem/temp move
        // gradually and the SNMP walks (hrProcessorLoad, hrStorage) aren't free.
        'interval' => (int) env('MYMATE_DEVICE_METRICS_INTERVAL', 30),
        'broadcast' => (bool) env('MYMATE_BROADCAST_METRICS', true),

        // Per-vendor SNMP OID profiles. Picked by a case-insensitive substring match on
        // the device's detected `vendor` (CaptureDeviceFacts), falling back to `default`
        // (host-resources MIB) for anything unmatched. Each profile declares how to read
        // each metric; unsupported metrics are just left null.
        //
        //   cpu_walk    walk this column and average the numeric values -> cpu %
        //   cpu_oids    GET these scalars, first numeric wins -> cpu %
        //   mem         'hrstorage' (host-MIB storage, RAM row) | 'cisco' (pool used/free)
        //   mem_*_walk  used/free columns for the 'cisco' strategy
        //   temp_oids   GET these scalars, take the max -> temp (÷ temp_divisor)
        //   temp_walk   walk this column, take the max -> temp (÷ temp_divisor)
        'profiles' => [
            'mikrotik' => [
                'cpu_walk' => '.1.3.6.1.2.1.25.3.3.1.2',   // hrProcessorLoad
                'mem' => 'hrstorage',
                // mtxrHlProcessorTemperature, then board temperature (whichever answers).
                // RouterOS reports these health temperatures in tenths of a degree C
                // (e.g. 440 -> 44.0C), so scale by 10.
                'temp_oids' => ['.1.3.6.1.4.1.14988.1.1.3.11.0', '.1.3.6.1.4.1.14988.1.1.3.10.0'],
                'temp_divisor' => 10,
                // Wireless (MIKROTIK-MIB): count the registration table (one row per associated
                // station) for client count; station signal strength for a CPE. SNR/CCQ over
                // SNMP aren't standardised on RouterOS - the RouterOS API path fills those in.
                'clients_walk' => '.1.3.6.1.4.1.14988.1.1.1.2.1.3', // mtxrWlRtabStrength (per client)
                'signal_oids' => ['.1.3.6.1.4.1.14988.1.1.1.1.1.4'], // mtxrWlStatStrength (station mode)
            ],
            // Ubiquiti airMAX (UBNT-AirMAX-MIB, enterprise 41112.1.4). RF is read from both the
            // per-station table (an AP -> averaged across its clients) and the radio's own
            // wlstat row (a CPE/station -> the link to its AP); whichever has data wins. airMAX
            // exposes CCQ but not SNR. cpu/mem via the host MIB (airOS is Linux-based).
            'ubiquiti' => [
                'cpu_walk' => '.1.3.6.1.2.1.25.3.3.1.2',   // hrProcessorLoad
                'mem' => 'hrstorage',
                'temp_oids' => [],
                'temp_divisor' => 1,
                'signal_walk' => [
                    '.1.3.6.1.4.1.41112.1.4.7.1.3', // ubntStaSignal (AP: per-client dBm)
                    '.1.3.6.1.4.1.41112.1.4.5.1.5', // ubntWlStatSignal (CPE: link dBm)
                ],
                'ccq_walk' => [
                    '.1.3.6.1.4.1.41112.1.4.7.1.6', // ubntStaCcq (AP: per-client %)
                    '.1.3.6.1.4.1.41112.1.4.5.1.7', // ubntWlStatCcq (CPE: %)
                ],
                'clients_value_walk' => ['.1.3.6.1.4.1.41112.1.4.5.1.15'], // ubntWlStatStaCount
            ],
            // Cambium ePMP (CAMBIUM-PMP80211-MIB, enterprise 17713.21). Station (SM) RF is a
            // scalar; the AP exposes per-SM RSSI/SNR tables and a connected-station count.
            // Both are wired so the one profile covers an SM and an AP. cpu/mem via the host MIB.
            'cambium' => [
                'cpu_walk' => '.1.3.6.1.2.1.25.3.3.1.2',   // hrProcessorLoad
                'mem' => 'hrstorage',
                'temp_oids' => [],
                'temp_divisor' => 1,
                'signal_oids' => ['.1.3.6.1.4.1.17713.21.1.2.3.0'],   // SM RSSI (dBm)
                'signal_walk' => ['.1.3.6.1.4.1.17713.21.1.2.30.1.4'], // AP: connectedSTAULRSSI per SM
                'snr_oids' => ['.1.3.6.1.4.1.17713.21.1.2.18.0'],     // SM SNR (dB)
                'snr_walk' => ['.1.3.6.1.4.1.17713.21.1.2.30.1.6'],    // AP: connectedSTAULSNR per SM
                'clients_value_walk' => ['.1.3.6.1.4.1.17713.21.1.2.10'], // cambiumAPNumberOfConnectedSTA
            ],
            'cisco' => [
                'cpu_oids' => ['.1.3.6.1.4.1.9.9.109.1.1.1.1.7.1'], // cpmCPUTotal5minRev
                'mem' => 'cisco',
                'mem_used_walk' => '.1.3.6.1.4.1.9.9.48.1.1.1.5',   // ciscoMemoryPoolUsed
                'mem_free_walk' => '.1.3.6.1.4.1.9.9.48.1.1.1.6',   // ciscoMemoryPoolFree
                'temp_walk' => '.1.3.6.1.4.1.9.9.13.1.3.1.3',       // ciscoEnvMonTemperatureValue
                'temp_divisor' => 1,
            ],
            // Host-resources MIB - net-snmp/Linux/Windows servers and anything that
            // implements it. No portable temperature OID, so temp stays null here.
            'default' => [
                'cpu_walk' => '.1.3.6.1.2.1.25.3.3.1.2',   // hrProcessorLoad
                'mem' => 'hrstorage',
                'temp_oids' => [],
                'temp_divisor' => 1,
            ],
        ],

        // host-MIB storage columns for the 'hrstorage' memory strategy - walk descr to
        // find the physical-RAM row, then used/size. Swap/virtual/cached rows are skipped.
        'hrstorage' => [
            'descr' => '.1.3.6.1.2.1.25.2.3.1.3',  // hrStorageDescr
            'size' => '.1.3.6.1.2.1.25.2.3.1.5',   // hrStorageSize (in alloc units)
            'used' => '.1.3.6.1.2.1.25.2.3.1.6',   // hrStorageUsed
        ],
    ],

    // Auto-discovery. A separate `scan` worker sweeps authorized subnets,
    // probes responders against the credential pool, and queues matches for review.
    // Safety (NFR-10): bounded blast radius + lockout-aware credential trials.
    'discovery' => [
        // How often the loop checks for subnets due to scan (s). Per-subnet cadence
        // is the subnet's own scan_interval_s - this is just the polling granularity.
        'check_interval' => (int) env('MYMATE_DISCOVERY_CHECK_INTERVAL', 30),
        // Default scan_interval_s for a new subnet (overridable per subnet).
        'default_scan_interval_s' => (int) env('MYMATE_DISCOVERY_SCAN_INTERVAL', 3600),
        // Cap usable hosts expanded per subnet so an over-broad CIDR can't blow up a
        // sweep (the action logs when this bites - no silent truncation).
        'max_hosts_per_subnet' => (int) env('MYMATE_DISCOVERY_MAX_HOSTS', 4096),
        // Lockout-aware spacing between *RouterOS login* attempts on one host (ms).
        'attempt_delay_ms' => (int) env('MYMATE_DISCOVERY_ATTEMPT_DELAY_MS', 200),
        // Wall-clock budget (s) for credential-probing responders in one sweep. Probing tries
        // SNMP + RouterOS + SSH per host, which is slow; when the budget is spent the sweep stops
        // probing new hosts (already-found candidates are skipped next run) so the scan job never
        // blows past its queue timeout. 0 = no budget (probe every responder).
        'scan_probe_budget_s' => (int) env('MYMATE_DISCOVERY_PROBE_BUDGET_S', 45),
        // SSH connect/auth timeout per credential attempt (s) - short so a filtered/closed 22 fails fast.
        'ssh_probe_timeout_s' => (int) env('MYMATE_DISCOVERY_SSH_TIMEOUT_S', 4),
    ],

    // RouterOS binary-API driver. Short connect timeout so a filtered
    // API port (e.g. BDR1:8728) fails fast instead of wedging a worker.
    'routeros' => [
        'timeout' => (int) env('MYMATE_ROUTEROS_TIMEOUT', 3), // seconds
    ],

    // Firmware upgrades. Ordered upgrades wait for each device to
    // come back online before upgrading its parent, so the path upstream is never cut.
    'upgrade' => [
        // Max time to wait for a rebooted device to return `up` before giving up (s).
        'reboot_wait_s' => (int) env('MYMATE_UPGRADE_REBOOT_WAIT', 300),
        // How often to re-check the device's status while waiting (s).
        'poll_interval_s' => (int) env('MYMATE_UPGRADE_POLL_INTERVAL', 10),

        // RouterOS package mirror/cache: choose a version + let the router pull the .npk from
        // us or straight from MikroTik.
        'download_base' => rtrim((string) env('MYMATE_ROUTEROS_DOWNLOAD_BASE', 'https://download.mikrotik.com/routeros'), '/'),
        // Channel NEWEST files, resolved to the latest version per channel for the picker.
        'channels' => ['stable', 'long-term', 'testing'],
        // Cached packages are kept this long, then swept (routes/console.php); manual delete too.
        'package_retention_days' => (int) env('MYMATE_ROUTEROS_PACKAGE_RETENTION_DAYS', 90),
    ],

    // Recent history: per-tick samples -> partitioned interface_samples,
    // retention by dropping old daily partitions. Additive to the live util path.
    'history' => [
        'enabled' => (bool) env('MYMATE_HISTORY_ENABLED', true),
        // Recent window kept (days); older daily partitions are dropped.
        'retention_days' => (int) env('MYMATE_HISTORY_RETENTION_DAYS', 14),
        // How many days of partitions to pre-create ahead of "today".
        'partitions_ahead' => (int) env('MYMATE_HISTORY_PARTITIONS_AHEAD', 3),
        // Loop cadence for partition maintenance (s) - light DDL, not the hot path.
        'maintain_interval' => (int) env('MYMATE_HISTORY_MAINTAIN_INTERVAL', 3600),
        // History API: target point count (server downsamples to ~this many buckets).
        'max_points' => (int) env('MYMATE_HISTORY_MAX_POINTS', 240),
        // History API: default lookback window (s) when from/to aren't given.
        'default_window' => (int) env('MYMATE_HISTORY_DEFAULT_WINDOW', 3600),
    ],

    // Device config backups. My Mate is the control plane for the
    // external **Rusted** backup engine (a Go sidecar that captures configs over SSH,
    // versions them in git, and exposes an HTTP API). These are the *defaults* - the
    // live URL/token/SSH fallback are operator-editable in Settings (App\Support\
    // BackupSettings, one encrypted `settings` row). See deploy/rusted/README.md.
    'backup' => [
        // Where the Rusted API listens. Localhost by design - Rusted binds to loopback
        // and only My Mate (same host) talks to it, so the bearer token never leaves the
        // box. NOT an SSRF surface (admin-only trusted infra), so OutboundHostGuard does
        // not apply here - that guard would wrongly reject this loopback default.
        'url' => env('RUSTED_API_URL', 'http://127.0.0.1:8410'),
        'token' => env('RUSTED_API_TOKEN', ''),
        // Per-call HTTP timeout (s). Generous - an SSH backup of a big config is slow.
        'timeout' => (int) env('RUSTED_API_TIMEOUT', 120),
        // Stable prefix for the Rusted device name My Mate registers per device
        // ("{prefix}{device_id}"), and the group it files them under.
        'device_prefix' => env('RUSTED_DEVICE_PREFIX', 'mymate-'),
        'group' => env('RUSTED_GROUP', 'mymate'),
        // Default SSH port for backups (RouterOS/most gear = 22). Rusted connects here.
        'ssh_port' => (int) env('RUSTED_SSH_PORT', 22),
    ],

    // MikroTik "The Dude" import (FR-Dude). The importer shells out to the
    // reverse-engineering script, then upserts the CSVs.
    'import' => [
        // Python 3 interpreter + the extractor script (repo-relative resolved at runtime).
        'python' => env('MYMATE_IMPORT_PYTHON', 'python3'),
        'extract_script' => env('MYMATE_IMPORT_SCRIPT', 'scripts/dude-extract.py'),
        // Default ceiling on the extraction subprocess (s) when a run doesn't set its
        // own. Big DBs + chart history are slow; the import screen lets each run raise
        // this per-import (see StoreImportRequest / import_runs.extract_timeout).
        'extract_timeout' => (int) env('MYMATE_IMPORT_EXTRACT_TIMEOUT', 1800),
        // Whole-job ceiling (s): extraction + import combined, enforced by the queue
        // worker. Generous (6h) so a huge history import is never killed mid-flight -
        // the import queue runs one job at a time, so a long run blocks nothing else.
        'job_timeout' => (int) env('MYMATE_IMPORT_JOB_TIMEOUT', 21600),
        // Max upload size (KB) for an uploaded dude.db. Default 4 GB - comfortably fits
        // even the largest Dude databases (which are almost all chart history).
        'max_upload_kb' => (int) env('MYMATE_IMPORT_MAX_UPLOAD_KB', 4194304),
        // chart_values rows are bulk-inserted in batches of this many.
        'history_batch' => (int) env('MYMATE_IMPORT_HISTORY_BATCH', 5000),
        // Map-coordinate compensation. Dude lays out tiny icons (~55-130px apart);
        // My Mate device cards are ~200x84px, so raw coords overlap badly. Each map's
        // placements are scaled off their nearest-neighbour distance toward
        // `min_spacing`, then a light overlap-resolution pass clears residual collisions
        // against the node footprint (+gap). Tunables:
        'layout' => [
            'min_spacing' => (int) env('MYMATE_IMPORT_MIN_SPACING', 240), // target typical centre-to-centre (px)
            // Scale off this percentile of per-node nearest-neighbour distances (0.5 =
            // median). Using the median (not the min) keeps the map faithful - a single
            // tight pair no longer blows the whole layout up; the declump clears the rest.
            'scale_percentile' => (float) env('MYMATE_IMPORT_SCALE_PCTL', 0.5),
            'max_scale' => (float) env('MYMATE_IMPORT_MAX_SCALE', 4.0),   // cap so a tight cluster can't explode a map
            'node_w' => (int) env('MYMATE_IMPORT_NODE_W', 240),           // node footprint + gap (x)
            'node_h' => (int) env('MYMATE_IMPORT_NODE_H', 120),           // node footprint + gap (y)
            'pad' => (int) env('MYMATE_IMPORT_PAD', 80),                  // top-left origin padding (px)
            'declump_iterations' => (int) env('MYMATE_IMPORT_DECLUMP_ITERS', 80),
        ],
        // History age-banding: each chart resolution covers a recency band so the
        // recent series is fine-grained and old data is coarse (one ts per band).
        // raw < 7d, 10min 7-90d, 2hour 90-365d, 1day older. Days, ascending bound.
        'history_bands' => [
            'chart_values_raw' => (int) env('MYMATE_IMPORT_BAND_RAW', 7),
            'chart_values_10min' => (int) env('MYMATE_IMPORT_BAND_10MIN', 90),
            'chart_values_2hour' => (int) env('MYMATE_IMPORT_BAND_2HOUR', 365),
            'chart_values_1day' => 0, // 0 = no upper bound (everything older)
        ],
    ],

    // Demo mode (customer-facing sales demo). When enabled the app runs the REAL UI +
    // WebSocket pipeline but fed 100% synthetic data by `mymate:demo --run`, and the SPA
    // auto-logs-in a read-only viewer + shows the marketing overlay. Never enable on a
    // real monitoring instance.
    'demo' => [
        'enabled' => (bool) env('MYMATE_DEMO', false),
        // Public read-only viewer the SPA auto-logs-in with (seeded by `mymate:demo --seed`).
        'email' => env('MYMATE_DEMO_EMAIL', 'demo@mymate.local'),
        'password' => env('MYMATE_DEMO_PASSWORD', 'explore-the-demo'),
        // Simulator cadence (seconds) + per-tick chance a device flaps up/down.
        'tick' => (int) env('MYMATE_DEMO_TICK', 3),
        'flip_chance' => (float) env('MYMATE_DEMO_FLIP_CHANCE', 0.015),
        // Where the public "contact sales" form delivers (empty = log only, no email).
        'contact_to' => env('MYMATE_CONTACT_TO', 'sales@athenanetworks.com.au'),
    ],

];
