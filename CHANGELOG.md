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
