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

### Added
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

### Fixed
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

[Unreleased]: https://github.com/AthenaNetworks/mymate/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/AthenaNetworks/mymate/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/AthenaNetworks/mymate/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/AthenaNetworks/mymate/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/AthenaNetworks/mymate/releases/tag/v1.0.0
