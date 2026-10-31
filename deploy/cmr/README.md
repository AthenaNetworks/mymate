# CMR controller (cmr-ctrl)

My Mate can act as the controller for MikroTik devices running in controller-managed mode
(CMR, RouterOS 7.26+). You point a device at My Mate, it pairs, and it shows up in the
Discovery review queue. Add it and it becomes a normal device.

The controller is a separate Go daemon, `cmr-ctrl`, from
[go-mikrotik-cmr](https://github.com/AthenaNetworks/go-mikrotik-cmr). My Mate runs it as a
sidecar, the same way it runs Rusted for backups:

- Devices connect to `cmr-ctrl` on **tcp/54321**. It terminates TLS itself with its own
  identity key, so this doesn't go through nginx.
- `cmr-ctrl` keeps its own admin API on `127.0.0.1:8411`, behind a bearer token. Every minute
  My Mate reads the paired devices from it (`mymate:cmr:sync`).
- The identity key lives in `/var/lib/mymate-cmr/ctrl.ed25519.seed`. Devices pin its
  fingerprint, so keep that file. If you lose it, every device has to be pointed at the new one.

## Install

The .deb, the LXC template and the Docker image ship the binary and set it up on install. On a
source install, or to redo it:

```bash
sudo deploy/cmr/provision.sh
```

That installs the binary (downloading the pinned release and checking its SHA-256 if it isn't
there), creates the `mymate-cmr` user, generates the admin token, installs and starts
`mymate-cmr.service`, and saves the URL and token into My Mate. Re-running it keeps the token and
the identity.

Then open **tcp/54321** from your management network to the My Mate host. In Docker, publish the
port (`docker-compose.yml` does) and keep the `cmr-data` volume.

## Pointing a device at it

Settings -> CMR shows the controller address and the fingerprint to configure on the device.
The address defaults to the host in `APP_URL`; set "Address devices use" if devices reach My Mate
by a different name or IP.

## What happens to a paired device

- It appears in Discovery with a `cmr` tag. Nothing is added to inventory until an admin adds it.
  Pairing is open, so anything that can reach the port can pair.
- Adding it creates a ping-only device at the address it connected from. Give it a credential in
  the inspector to poll it over SNMP or the RouterOS API, and backups work like any other device.
- If a device is behind NAT, the address it connects from is the NAT address. Change its
  management IP in the inspector; the sync never overwrites it. Two devices behind the same NAT
  address can't both be added until the first has its real IP.
- If you ignore a device it stays ignored, however often it reconnects. If you delete a device
  that's still paired, it comes back to Discovery.

## Limits (v1)

- Open pairing only.
- No config push from My Mate yet. The daemon supports VLAN pushes; the inspector UI for them
  is the next phase.

## Upgrading cmr-ctrl

Bump `deploy/cmr/VERSION` and copy the two `cmr-ctrl-linux-*` lines from the release's own
`SHA256SUMS` into `deploy/cmr/SHA256SUMS`. The registry file is compatible across versions, so
swapping the binary is enough.
