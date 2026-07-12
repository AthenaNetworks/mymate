# My Mate - build & packaging

Builds a single, self-contained `.deb` and a Proxmox **LXC template**. A user installs one
package and a clean Debian 13 / Ubuntu box comes up fully provisioned - free and open source,
no device limits.

```
deploy/build/
  build-fping.sh       (re)build fping >= 5.5 from source -> deploy/build/vendor/fping
  build-deb.sh         orchestrator -> dist/mymate_<ver>_amd64.deb
  build-lxc.sh         -> dist/mymate_<ver>_<distro>_amd64.tar.zst  (Proxmox LXC template)
  install.sh           shipped in dist/; checks the OS (adds the PHP 8.4 PPA on Ubuntu) then installs
  tests/verify-install.sh   real post-install check (services active, nginx serving, health ok)
  vendor/fping         the fping 5.5 (JSON) binary bundled into the package (tracked in git)
  VERSION              package version
  package/             DEBIAN control + maintainer scripts (postinst/prerm/postrm)
  files/               systemd units, nginx site, php-fpm pool, .env template, firstboot.sh, enable-icmp.sh
```

**Targets:** Debian 13 (native PHP 8.4) and Ubuntu 22.04+ (`install.sh` adds the Ondřej Surý
PHP PPA). Verified end-to-end on both, plus the Proxmox LXC template - see *Verification*.

## Build the .deb

```sh
# one-time (only if deploy/build/vendor/fping is missing): build fping 5.5
deploy/build/build-fping.sh

deploy/build/build-deb.sh
#   VERSION=1.2.0 deploy/build/build-deb.sh
```

Output: `dist/mymate_<ver>_amd64.deb` + `dist/install.sh`. Needs `php`, `composer`, `npm`,
`dpkg-deb`, `tar` on the build host (and the repo's `node_modules` - run `npm ci` first).

## Proxmox / LXC template

```sh
deploy/build/build-lxc.sh          # -> dist/mymate_<ver>_debian-13_amd64.tar.zst
```

A full root filesystem with My Mate preinstalled that **self-provisions on first boot**
(fresh `APP_KEY`/DB password/Reverb secret + correct hostname, via `mymate-firstboot.service`).
Needs `podman` (rootful) + `zstd`. On the Proxmox host: copy the `.tar.zst` into
`/var/lib/vz/template/cache/`, then

```sh
pct create <VMID> local:vztmpl/mymate_<ver>_debian-13_amd64.tar.zst \
  --hostname mymate --cores 2 --memory 2048 --swap 512 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp --features nesting=1 --unprivileged 1 --onboot 1 --start 1
```

Browse `http://<container-ip>` and create your admin
(`pct exec <VMID> -- sudo -u mymate php /opt/mymate/artisan mymate:user:create --admin`).

## Install (target - Debian 13 / Ubuntu 22.04+)

```sh
sudo apt install ./mymate_<ver>_amd64.deb     # or: sudo ./install.sh   (adds the PHP 8.4 PPA on Ubuntu)
```

apt resolves every dependency (php-fpm, nginx, postgresql, redis, ...) and runs the postinst:

1. creates the `mymate` system user,
2. enables ICMP for the service user (see *ICMP* below),
3. provisions the local PostgreSQL role + database (random password),
4. writes `/opt/mymate/.env` with a generated `APP_KEY` + Reverb secret + detected host,
5. runs migrations,
6. configures nginx + a dedicated php-fpm pool,
7. enables the `mymate-{horizon,reverb,loop,scheduler}` systemd services.

Then create your admin and open the console:
```sh
sudo -u mymate php /opt/mymate/artisan mymate:user:create --admin
# browse http://<server>   (if by another hostname: mymate:configure-host <host>)
```

## Verification

`deploy/build/tests/verify-install.sh` (run as root on the target, or in a systemd container)
asserts the install actually **works**: every service is `active` (postgresql, redis, php-fpm,
nginx, mymate-{loop,reverb,horizon,scheduler}), nothing is failed, nginx serves the SPA on
`:80`, `/api/health` is green, unauth `/api/*` is 401, the app boots, and the bundled fping
does `--json` **as the mymate user**. Verified on Debian 13, Ubuntu 24.04, and the LXC template
(booted fresh) - all pass.

### ICMP is a hard requirement

Up/down monitoring runs `fping` as the non-root `mymate` user, so ICMP must work for that user.
The package enables it two ways, most-portable first, and **verifies it - a host that can do
neither aborts the install**:

1. **Unprivileged ping sockets** via `net.ipv4.ping_group_range` (shipped as
   `/etc/sysctl.d/99-mymate.conf`). A namespaced sysctl -> works on bare metal, VMs **and
   unprivileged Proxmox LXC**. Primary mechanism.
2. **`CAP_NET_RAW`** on the fping binary - fallback where the sysctl can't be set.

Docker/podman without either: run with `--sysctl net.ipv4.ping_group_range="0 2147483647"`.

## CI/CD

GitHub Actions (`.github/workflows/`):
- **ci.yml** - runs the test suite + typecheck (Postgres service) and builds the `.deb` on every push/PR.
- **release.yml** - on a `v*` tag, builds the `.deb` + the Proxmox LXC template and publishes them to a GitHub Release.

## Notes

- HTTPS: `sudo certbot --nginx` (a Recommended dep), then
  `sudo -u mymate php /opt/mymate/artisan mymate:configure-host <host> --https`.
- The `.deb` ships plain PHP - free and open source, nothing obfuscated.
