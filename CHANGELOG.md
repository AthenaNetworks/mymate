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
  count for wireless gear, over the RouterOS API (MikroTik) or SNMP profiles, in the
  Health section and pushed live to the map.
- **Custom SNMP sensors.** Define your own OIDs to poll and graph (interface errors,
  PoE draw, UPS charge, a probe - anything the gear exposes), scoped to devices,
  with the value shown in the inspector. Managed in Settings.
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

[Unreleased]: https://github.com/AthenaNetworks/mymate/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/AthenaNetworks/mymate/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/AthenaNetworks/mymate/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/AthenaNetworks/mymate/releases/tag/v1.0.0
