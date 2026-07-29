# Changelog

All notable changes to My Mate are recorded here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and the project follows
[semantic versioning](https://semver.org/).

**Maintaining this file** (see [BUILD.md](BUILD.md#cutting-a-release)): keep an
`Unreleased` section at the top and add entries there as you go, grouped under
Added / Changed / Fixed / Removed. When you cut a release, rename `Unreleased` to
the new `vX.Y.Z` with the date, bump the root `VERSION` file to match, then tag.
Write it for a human deciding whether to upgrade - one line per change, not a dump
of commit subjects.

## [Unreleased]

### Fixed
- **Redis no longer grows until it gets OOM-killed on large fleets.** The live map updates
  (interface load, device metrics, up/down, latency) were queued for delivery; on a big fleet the
  per-tick stream out-ran the worker draining it and piled up in Redis until the kernel killed
  `redis-server` and took monitoring down with it. These ephemeral updates now broadcast inline, so
  they never touch the queue - a delayed frame is stale anyway. As a backstop the packaged installs
  now cap Redis memory (`maxmemory` at 40% of RAM, `allkeys-lru`), and Horizon keeps far less
  completed-job history and ignores the routine poll jobs entirely. Nothing here is durable, so the
  cap and the shorter history are safe. Apply the cap to an existing box now with
  `redis-cli CONFIG SET maxmemory <~40% of RAM>` and `redis-cli CONFIG SET maxmemory-policy allkeys-lru`.

### Added
- **Sales demo: charts are full from the first click.** `mymate:demo --seed` now backfills 24 hours
  of per-minute history for every mock device - throughput, CPU/memory/temperature, and ping
  latency/loss/jitter - so the device inspector never shows "No history yet" on the demo. The
  backfill uses the same generators as the live simulator (both keyed on wall-clock time), so the
  simulator's live samples continue the backfilled series without a visible seam.
- **Sales demo: synthetic ping data.** The simulator now records latency/loss/jitter each tick
  (down devices report 100% loss and no RTT, mirroring the real ping loop), so the inspector's
  Latency and Loss sparklines are populated and live-update. See [deploy/demo/README.md](deploy/demo/README.md).

### Fixed
- **Ping monitoring survives the sharding upgrade.** After deploying the sharded ping sweep
  (1.4.0), a still-running pre-upgrade dispatcher (`mymate:loop`) kept queueing old-format
  sweep jobs whose payloads lack the new shard fields - deserialisation skips the constructor,
  so every sweep crashed on an uninitialised property and up/down status, live latency, and
  ping history all silently froze until the daemons were restarted. Old-format jobs now fall
  back to a whole-fleet sweep. (As always, restart `mymate-loop`/Horizon after upgrading.)
- **Sharded sweeps record complete latency history.** With `ping.shards` > 1, the latency
  history write-throttle was global, so only the first shard to sweep each interval recorded
  history - devices in other shards got sparse, random samples. The throttle is now per shard.
- **Dropdown options are readable on a light-mode OS.** Every native select popup (Add device's
  poll method/type/agent, list filters, settings, and so on) could render as a white list with
  invisible white text when the browser/OS runs in light mode - the options inherited the app's
  translucent control background, which popup surfaces can't composite. Options now get an
  explicit solid dark surface, app-wide.
- **Geo map no longer opens on an empty world view.** On the first visit, the geographic map
  could show a fully zoomed-out world with no device pins (working only on the second open):
  the Leaflet map is created once the map config loads, but the pin-drawing and
  fit-to-devices steps only reacted to the device list - which was usually already cached -
  so they had run (and done nothing) before the map existed. They now also re-run when the
  map is created, so the first open drops the pins and zooms to them like every later open.
- **Sales demo: history no longer silently goes stale.** The demo simulator created history
  partitions only once at startup, so once the daemon outlived the create-ahead window (a few
  days) every history insert failed silently and the charts froze. History inserts now roll the
  partitions forward and retry when they hit a missing partition, so a long-running simulator
  keeps recording indefinitely.

## [1.4.0] - 2026-07-24

### Added
- **Anonymous wallboard links (GitHub #15).** Share a map's live wallboard as a read-only, no-login
  link for a NOC screen or a status page. On any map, open the map menu and pick "Share wallboard"
  to mint an unguessable link; turn it off or remove it to revoke access at any time. The public
  view is read-only, polls for live status and link load, and never shows management addresses or
  credentials - only what the map draws.
- **Windows remote agent (GitHub #14).** The agent now builds for Windows (amd64 / arm64) and can
  install itself as a Windows service: `mymate-agent.exe install --url ... --token ...` registers
  and starts it (auto-start, restart on failure), with `start` / `stop` / `status` / `uninstall`
  to manage it. Same single static binary, same outbound-only model. Config is read from the
  environment or a file the installer writes, so Linux/macOS behaviour is unchanged.
- **Geographic map mode (GitHub #11).** Toggle a map into geographic mode and your own device
  nodes sit over a real map background, positioned by each device's coordinates - set them from an
  address or by dragging on the map, or auto-filled from an SNMP location. Devices sharing a spot
  collapse into one stack node so a site doesn't turn into a pile of overlapping cards.
- **OSPF neighbours and link cost (GitHub #11).** Device tiles show the OSPF full-neighbour count,
  and links show the OSPF cost out of each end - read over the RouterOS API, including for MikroTiks
  that are otherwise polled over SNMP (attach a RouterOS API credential).
- **Export and import a map (GitHub #11).** Save a map's layout to a JSON file and import it back,
  to move a map between instances or keep a copy.
- **Free-text notes and labels on maps (GitHub #11).** Drop a note anywhere on a map to annotate it;
  colour it and drag it into place.
- **Real device links between child maps on an overview (GitHub #9).** An overview map can now show
  the actual device links that cross between the sub-maps placed on it, aggregated per pair and
  toggleable, so you can see the real wiring without a tangle.
- **More alerting and map detail (GitHub #10, #11).** Per-interface "port down" alerts, a
  low-throughput alert, link port-speed shown on the map, a per-device ping source address, a map
  breadcrumb for nested maps, and a custom sensor that can walk an SNMP table and reduce it to one
  value.

### Changed
- **Redis runs with persistence off (GitHub #16).** My Mate uses Redis only as a transient broker
  (queues, cache, broadcasting), so the packaged installs now disable RDB snapshots and AOF and
  never block writes on a failed save. This avoids the stock-Redis behaviour that, on large fleets,
  filled the disk with the RDB dump and spiked memory via `redis-check-rdb`. Applies to the
  `.deb`, LXC and Docker Compose; nothing of record lives in Redis, so there's no data to lose.
- Added [REQUIREMENTS.md](REQUIREMENTS.md) with CPU/RAM/disk sizing guidance and how history
  retention bounds disk use.

### Fixed
- Overview maps: child-map nodes can be detached from a canvas, and the empty-state and add-map
  flash were cleaned up.
- Geographic mode: dragging a device no longer zooms the map out, and fit-to-bounds no longer
  over-zooms when devices are close together.
- Removed a stray backslash that showed literally in a few UI strings.

## [1.3.0] - 2026-07-16

### Added
- **Overview maps: child-map nodes and manual links (GitHub #9).** Place a map as a node on
  another map (Add map on the toolbar), double-click it to drill in, and draw links between
  those map-nodes to build a top-level topology - no device or interface needed. Nesting is
  cycle-guarded, nodes drag to reposition, and links drag to remove.
- **Links can be tagged by media type.** Fiber / Ethernet / Wireless / Other, styled on the
  map so link types read at a glance - manual overview links colour by medium, and device
  links keep their load colour but pick up the medium's line style (wireless dashes). Set it
  when creating a link, or click an overview link to change it.
- **Remote agents now report CPU / memory / temperature.** A device polled through a remote
  agent previously showed no resource metrics; the agent now reads cpu/mem/temp (SNMP by the
  same per-vendor OID profile the central poller uses, or the RouterOS API) and reports them
  back, so agent-monitored devices get the same tiles and history as centrally-polled ones.
- **Remote agents support SNMPv3.** The agent now authenticates with the full v3 USM parameter
  set (user, auth + privacy protocols/passphrases) for both polling and discovery, not just a
  v1/v2c community - matching the central poller.
- **Internet/upstream card shows latency, not load.** An internet device now displays its
  ping latency (and a packet-loss badge) instead of a load/cpu/mem tile, coloured by a
  per-device quality band you set in the device editor - green at or below the "good"
  threshold, red at or above the "bad" one, amber in between (defaults 30ms / 150ms).
  Latency updates live on the map as sweeps run.
- **Discovery probes SNMP, RouterOS and SSH per host.** A responding host is now tried
  against all three credential types in one pass, so both its poll credential (SNMP or
  RouterOS) and a matching SSH credential (for config backups) are linked when you approve
  it. A host that only matches SSH becomes a ping-only device with backups configured.
- **Credential tags in the review queue.** Each discovered host shows a tag for every
  credential that authenticated against it (SNMP / RouterOS / SSH, named), so you can see
  at a glance what it'll be polled and backed up with before approving it.
- **Live scan-progress on the discovery page.** A sweep in progress now shows a banner with
  an animated progress bar and the subnet(s) being scanned - for scheduled scans too, not
  just ones you kicked off - so it's obvious discovery is working while devices trickle in.
- **Remote-agent scans stream live progress.** The agent now reports a sweep as it runs
  (hosts pinged so far / total, and responders identified so far) instead of only at the end,
  so the discovery page shows a real progress bar and a live "found" count for agent scans -
  and the loop no longer stacks overlapping sweeps of the same subnet.
- **Remote agent as a `.deb`.**
- **Custom device icon and colour.** Edit a device to pick its map glyph from a set of
  role icons (router, switch, AP, dish, server, firewall, camera, NVR, and more) and tint
  it any colour - or leave it on Auto to keep the vendor/type icon. The chosen icon now
  shows identically on the map node and in the inspector header.

### Changed
- **Links with no known speed default to 1 Gbps.** When neither end of a link reports an
  interface speed (e.g. RouterOS returns 0 for ifSpeed over SNMP), the link now assumes a 1G
  circuit so it still colours by load instead of staying neutral grey. A per-link bandwidth
  override always wins, and ping-only links (no interface either end) stay neutral.

### Fixed
- **MikroTik product photos show again (model parsed from modern sysDescr).** Newer RouterOS
  reports its board name with the version appended ("RouterOS RB5009UPr+S+ 7.23.2 (stable)"),
  which the model parser's end-anchored pattern didn't match - so the model came back empty,
  the product-photo lookup had nothing to resolve, and the card fell back to the "MT" monogram.
  The board name is now read regardless of a trailing version, and a modelless build's bare
  version is no longer mistaken for a model. The map glyph also retries a freshly-fetched photo
  a few times so it appears without a page reload. (Modelless CHR virtual routers still show the
  MikroTik monogram - there's no product image for them.)
- **CPU / memory now read over SNMP for wired devices.** A device answering "no such object"
  for an OID it doesn't implement (e.g. a wired router or switch queried for a wireless-only
  signal OID) was treated as a total poll failure, so its cpu/mem/temp came back blank even
  though they were readable. Such an absent OID is now handled per-metric, so each metric is
  read independently as intended. Genuine transport failures (timeout/filtered) still isolate
  the device. Also fixed MikroTik temperature reading 10x too high (it's reported in tenths
  of a degree).
- **Links attach to the sides you drew them from.** Dragging a link now binds each end to
  the exact handle you started and finished on (e.g. bottom-to-top) instead of always
  springing the "from" end to the top of the node. Links drawn the old way, or via the Add
  link dialog, still auto-float to whichever sides face each other.
- **Remote-agent connections stay up, and offline is detected fast.** The hub sends a WebSocket
  keepalive ping to each connected agent every 30s (and the `/agent` proxy read timeout was
  raised to an hour), so a quiet link is no longer dropped by nginx or a stateful firewall.
  Each ping/pong refreshes the agent's heartbeat, and a reaper marks an agent offline if it goes
  silent for 90s - so a blackholed link (no TCP close) flips offline within ~a minute instead of
  looking online until the proxy timeout, and job dispatch/UI never treat a gone agent as live.
- **Discovery scans now find every responder.** Two bugs meant hosts that clearly respond
  (e.g. routers on `.253`/`.254`) were missed: fping was resolved via `PATH`, so a
  web-request context whose `PATH` omitted `/usr/local/sbin` couldn't find it and every
  sweep came back empty (now resolved by absolute path); and a `/24` sweep could exceed the
  scan job's timeout while probing serially, so late addresses were never reached (probing
  now runs within a time budget and defers the rest to the next sweep instead of timing out). The agent now ships as a Debian package (amd64 / arm64 /
  armhf) that asks for the server URL and agent token during install, writes the config,
  and sets up a hardened systemd service - `sudo apt install ./mymate-agent_<ver>_<arch>.deb`.
  Change it later with `dpkg-reconfigure mymate-agent`. Built and attached to releases by CI.

## [1.2.0] - 2026-07-15

### Added
- **SNMPv3.** Credentials can now use SNMPv3 (USM) alongside v1/v2c: pick a security
  level (noAuthNoPriv / authNoPriv / authPriv), an auth protocol (MD5, SHA, SHA-2) and
  a privacy protocol (DES, AES), with the passphrases stored encrypted. Central polling,
  discovery, device facts and custom sensors all authenticate with v3 where configured.
- **Add & edit devices from the map.** An Add device button on the map toolbar creates a
  device and drops it on the current map; the inspector gains an Edit device options
  dialog (name, IP, poll method, credential, type, and a monitoring on/off toggle) - no
  more round-trip to the Devices page.
- **Generic internet / uplink object.** Add a placeholder upstream node from the map
  (ping-only, defaults to pinging 1.1.1.1) and link a device's uplink interface back to
  it - so you can monitor an uplink even when the upstream device or port was never
  discovered.
- **Factory reset.** A clean-slate reset (the `mymate:factory-reset` command and an
  admin-only, password-confirmed Danger zone in Settings) wipes all monitoring data -
  devices, maps, credentials, history, everything - and keeps only admin accounts.
- **Cross-map links.** Link a device to any other device, including one on a different
  map, from the inspector's Add link button - search for the far end by name or IP,
  bind each interface, done. The link shows as a portal on each map where only one end
  is present. Previously links could only be drawn by dragging two devices together on
  the same map.
- **RouterOS upgrades: choose a version + package mirror.** Pick a specific RouterOS
  version (from the release channels or typed in) instead of only "latest", and
  choose where each router pulls the package from - straight from MikroTik, or from a
  local mirror. My Mate downloads the per-arch `.npk` once, caches it (served to
  routers over an unguessable token URL), and keeps it for 90 days (configurable,
  with manual delete). The chosen version is fetched onto the device and verified
  before it reboots. Managed on the Upgrades page.
- **Map restyle + device icons.** The topology map got a visual pass: a selected
  device now clearly stands out (emerald ring, halo, lift) and its links come forward
  while the rest fade back; a "blueprint" grid replaces the stock dot field; and a
  bespoke zoom/fit control cluster replaces the default one. Device tiles now show the
  real MikroTik product photo for the model - fetched from MikroTik and cached locally
  the first time that model is seen - falling back to a clean drawn family icon
  (router / switch / AP / dish / server) for everything else.
- **Latency, jitter and packet-loss monitoring.** The ping sweep now records
  round-trip time and loss per device (live values + history), charted in the
  device inspector's Health section - shown for ping-only devices too. High-metric
  alert rules gained latency and loss thresholds.
- **Maintenance windows.** Schedule a span (with a device scope) during which alerts
  are suppressed for the in-scope devices, so planned work doesn't page anyone - no
  false alarms while it's on, and no false recovery when it ends. Managed on the
  Alerts screen.
- **Alert acknowledgement** and **more notification channels.** Mark a fired alert
  as handled (recording who/when); and deliver via a generic Webhook, Discord,
  Telegram or PagerDuty in addition to email/Slack/Teams/Messenger.
- **Wireless / RF metrics.** Signal strength, SNR, connection quality and client
  count for wireless gear, in the Health section and pushed live to the map. Read
  over the RouterOS API (MikroTik) and over SNMP for **Ubiquiti airMAX** (signal /
  CCQ / client count) and **Cambium ePMP** (RSSI / SNR / connected-SM count) - each
  profile handles both AP mode (averaged across associated stations) and station/CPE
  mode. Vendor SNMP RF profiles are extensible via config.
- **Custom SNMP sensors.** Define your own OIDs to poll and graph (interface errors,
  PoE draw, UPS charge, a probe - anything the gear exposes), scoped to devices,
  with the value shown in the inspector. Managed in Settings.
- **SSH private keys on credentials.** An SSH credential can now authenticate with a
  pasted private key instead of (or alongside) a password. Stored encrypted, never
  returned.
- Alert rules for **failed config backups** and **high device metrics** (CPU,
  memory or temperature over a threshold). Both support the same targeting
  (all / device type / map / specific devices), sustained-duration gate and
  recovery notifications as the existing rules; high-metric ignores stale readings
  so a device that stopped reporting doesn't alert on a frozen value.
- The Dude import screen now has an **extraction time limit** control, so a very
  large `dude.db` (lots of chart history) can be given more time to reverse-engineer
  instead of being cut off. Also available on the CLI as `--extract-timeout`. (#3)
- **System status** panel in Settings: at-a-glance health for the database, Redis,
  background workers, the polling loop, the WebSocket server and the backup engine -
  so "why isn't X working?" is self-diagnosable.
- **Guided upgrade**: when a newer release is out, Settings now shows the release
  notes and the exact upgrade command for each install type (package, Docker, LXC),
  not just a "an update is available" note.
- The import screen shows the maximum upload size and checks the file before it
  uploads - an oversized `dude.db` is caught instantly (with the CLI import
  suggested) instead of failing after a long transfer, and very large files get a
  nudge toward the more reliable CLI import.

### Changed
- **Graceful upgrades.** The Debian package and Docker image now clear stale compiled
  caches (config/views/events) and always run migrations before restarting the
  workers, so new code never meets an old schema. The package also holds the console
  in maintenance mode during the migration and comes back up automatically. Added an
  Upgrading section to the README covering all three installs (LXC = install the new
  `.deb` into the container).
- The default upload size limit for a `dude.db` is now 4 GB (was 512 MB), across the
  app, nginx and PHP, so even the largest databases upload without tuning. Imports
  also get a generous whole-job time budget so a big history import isn't killed
  part-way through. (#3)
- Error notifications now wrap to show the full message and stay until dismissed,
  instead of being truncated to one line and disappearing after a few seconds (you
  couldn't read long errors like the import size message). (#3)
- The installer and first-boot now detect the machine's reachable IP via its default
  route when setting `APP_URL`, rather than the first `hostname -I` entry (which can
  be a secondary interface).
- Mobile: the console feels more app-like on phones and tablets - the page no longer
  rubber-bands or pull-to-refreshes when you drag the map, taps don't flash or
  double-tap-zoom, text doesn't inflate on rotation, and the chrome now respects
  device safe areas (notch / home indicator). Builds on the existing responsive
  layout (drawer navigation, off-canvas device inspector).

### Fixed
- The Dude import now brings in links the operator drew but wasn't bandwidth-monitoring.
  Previously only monitored links were imported; plain topology lines (which have no
  monitored-link object in the Dude DB) were dropped, so maps came in missing links. They
  now import as plain device-to-device links, skipping any pair a monitored link covers.
- The device LOAD tile no longer shows a dash when interface speed is unknown: it now
  falls back to the busiest interface's absolute throughput (e.g. 5.0M, 1.2G). The
  percentage form needs a known link speed, which RouterOS virtual interfaces and any
  port with no negotiated rate don't report.
- Device product photos now show reliably: SNMP model capture no longer stores raw board
  ids (like `0x0002`) that can't map to a product page, the map glyph skips the lookup
  for unresolvable models, and the icon cache is written world-readable so a packaged
  install's web user can serve it.
- The map list scrolls when it's longer than the screen (previously it just ran off the
  bottom with no way to reach the maps below).
- Native dropdowns and number inputs in the credential and device forms no longer render
  white-on-white in dark mode.
- The latest-config viewer opens as a full-window modal instead of being trapped inside
  the inspector sidebar.
- The Settings "System status" panel no longer reports the polling loop as stopped
  right after an upgrade: it falls back to recent polling activity when the loop's
  heartbeat is stale (the long-running loop process may not have restarted yet).
- Login on an LXC/VM would flash the first screen and bounce straight back to the
  login page when the instance was reached by an address that wasn't baked into
  `SANCTUM_STATEFUL_DOMAINS` at first boot (a changed/DHCP IP, a hostname or DNS
  name). The app is served same-origin, so it now trusts the address the browser
  actually uses for cookie auth - no `.env` editing needed. (#4)
- Docker: `dude.db` uploads through the browser were capped at PHP's 2 MB default
  (the image had no upload override). The image now allows the full 4 GB.

## [1.1.1] - 2026-07-13

### Fixed
- RouterOS device metrics (CPU / memory / temperature) and device facts
  (vendor / model / uptime) are captured over the API again. The API calls were
  missing the `/print` action, which real RouterOS rejects with "no such command",
  so every MikroTik silently reported nothing in 1.1.0. SNMP devices were not
  affected.

## [1.1.0] - 2026-07-13

### Added
- Device resource metrics: CPU, memory and temperature per device, over SNMP
  (per-vendor OID profiles) or the RouterOS API. Shown on the map tiles and the
  device list, with history charts in the inspector.
- Rolling firmware upgrades page: pick devices, get a dependency-ordered plan
  (furthest-downstream first, with the topology shown), reorder it by hand, then
  run it one device at a time - each upgrade waits for the previous device to come
  back online so a reboot never cuts the path.
- Backups page: set an automatic backup schedule, browse every stored config
  version from the git-backed store, and diff any two versions.
- The config backup engine (Rusted) is now bundled and auto-provisioned by the
  `.deb`, Proxmox LXC and Docker installs, so device backups work out of the box.
- Per-device SSH credentials, separate from the SNMP / RouterOS polling credential.
- MikroTik key-based SSH bootstrap: install a generated SSH key on a device over
  the RouterOS API, then back it up over SSH (RouterOS won't hand its config back
  over the API).
- A full device edit dialog (name, IP, poll method, credential, type, parent) with
  an enable/disable-monitoring toggle, and a `mymate:user:admin` command to grant
  or revoke admin from the CLI.
- Credential picker on the add-device form and the inspector, so an SNMP/RouterOS
  device actually gets a community/login instead of failing discovery.
- Links to ping-only devices: bind a link from the interface end when the other
  end has none.
- Update check: Settings shows when a newer release is available on GitHub.

### Fixed
- Mangled apostrophes that showed a literal backslash in several dialogs.

## [1.0.0] - 2026-07-12

Initial public release (MIT licensed). A modern, web-based replacement for
MikroTik's The Dude:

- Up/down monitoring via a batched fping sweep, pushed live over WebSockets.
- Interactive topology map with interface-to-interface links coloured by
  utilisation in real time; multiple maps and a wallboard/dashboard mode.
- Per-interface throughput over SNMP (64-bit counters) or the RouterOS API.
- Subnet auto-discovery with a review queue.
- Alerting (email / Slack / Teams / Messenger), outage history, per-link history
  charts.
- Config backups, bulk firmware upgrades, MikroTik dude.db import.
- Operator accounts with a read-only viewer tier.
- Remote agents for out-of-band networks. Ships as a `.deb`, a Proxmox LXC
  template and a Docker image.

[Unreleased]: https://github.com/AthenaNetworks/mymate/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/AthenaNetworks/mymate/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/AthenaNetworks/mymate/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/AthenaNetworks/mymate/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/AthenaNetworks/mymate/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/AthenaNetworks/mymate/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/AthenaNetworks/mymate/releases/tag/v1.0.0
