# Device config backups - Rusted integration

My Mate captures **device configuration backups over SSH** by driving the external
[**Rusted**](https://github.com/JoshFinlayAU/rusted) engine - a single-binary Go tool
(a modern RANCID/Oxidized) that SSHes to a device, dumps + normalises its config, and
versions it in git. My Mate is the **control plane / API client**: it never re-implements
SSH/driver/git logic - it registers devices with Rusted and proxies its HTTP API. This is
the same server-side-relay pattern Rusted ships for LibreNMS (`librenms-module/`), both
being Athena Networks projects.

```
 ┌─────────────┐   HTTP (bearer, loopback)   ┌──────────────┐   SSH   ┌─────────┐
 │  My Mate    │ ─────────────────────────▶ │  Rusted API  │ ──────▶ │ device  │
 │ (Laravel)   │  register / backup / read   │  (Go, :8410) │  /export│ (RouterOS...)
 └─────────────┘ ◀───────────────────────── └──────┬───────┘         └─────────┘
   inspector UI      status / history / config      │ git commit
   Settings card                                     ▼  /var/lib/rusted/backups
```

Backups are **opt-in per device**; the vendor driver is auto-suggested (RouterOS ->
`mikrotik_routeros`) and overridable. My Mate caches each device's last-run status so the
inspector shows it without hitting Rusted on every render.

---

## 1. Install the Rusted sidecar

Rusted isn't bundled (it's a separate Go binary). Build + install it once on the My Mate host:

```sh
# Needs Go 1.26+ and git (no prebuilt binary - it's built from source). Then:
git clone https://github.com/JoshFinlayAU/rusted.git
cd rusted
./install.sh --global            # builds + installs /usr/local/bin/rusted,
                                 # and AUTO-GENERATES a random api_token + secret
                                 # into the config (never overwrites an existing one)
```

Create its data dir and a dedicated user:

```sh
sudo useradd --system --home /var/lib/rusted --shell /usr/sbin/nologin rusted
sudo mkdir -p /var/lib/rusted/backups /etc/rusted
sudo chown -R rusted:rusted /var/lib/rusted
# initialise the git-backed backup store
sudo -u rusted git -C /var/lib/rusted/backups init -q
```

## 2. Configure it

`install.sh` already wrote a config with a **generated `api_token` + `secret`**. Two things
you MUST change: the listen port (Rusted's default `:8080` clashes with My Mate's dev
server / other services on this host) and, ideally, bind to loopback. Find and edit the
config (`rusted config path` prints its location; typically `/etc/rusted/config.toml` for a
`--global` install, else `~/.config/rusted/config.toml`):

```sh
rusted config path                             # where the config lives
sudo -e "$(rusted config path)"                # set: api_addr = "127.0.0.1:8410"
```

Then **read the token back out** - this is "how you get the token" for the My Mate card:

```sh
sudo grep api_token "$(rusted config path)"    # -> api_token = "7f3a...d4"  (copy this value)
```

> Only if you'd rather set your own token instead of the generated one:
> `openssl rand -hex 32` and paste it as `api_token`. Not required - the generated one is fine.

Key points:
- **`api_addr = "127.0.0.1:8410"`** - loopback only. My Mate is the sole client, so the
  token never leaves the host and Rusted needs no public exposure/TLS. (Change from the
  stock `:8080`, which is taken on this box.)
- **`api_token`** - the value My Mate must send (next step); copy it from the config.

## 3. Run it as a service

```sh
sudo cp deploy/rusted/rusted-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now rusted-api
curl -s http://127.0.0.1:8410/healthz          # -> {"status":"ok"}
```

> Scheduling note: **My Mate owns backup scheduling** (`mymate:backup:run --all`, nightly at
> 02:30 via `routes/console.php`). Do **not** also enable Rusted's own `rusted-backup.timer`
> - that would double-run. Keep only the API service above.

## 4. Point My Mate at it

Two ways (the in-app one wins per field):

- **`.env`** (defaults) - set `RUSTED_API_URL=http://127.0.0.1:8410` and
  `RUSTED_API_TOKEN=<the api_token>`, then `php artisan config:clear`.
- **In-app** (recommended, encrypted at rest) - **Settings -> Config backup engine**: URL,
  token, an optional **default SSH login**, then **Test connection** (pings `/healthz`).

Run the migration that adds the per-device backup columns:

```sh
php artisan migrate            # 2026_07_10_000001_add_config_backup_to_devices_table
```

Restart the workers so the new `backup` Horizon queue + schedule are picked up:

```sh
php artisan horizon:terminate  # supervisor restarts it; or restart your horizon service
```

---

## Credentials model

Backups run over **SSH**, which is separate from My Mate's SNMP/RouterOS-API polling creds.
`RegisterBackupDevice` resolves a device's SSH login in this order:

1. the device's **own My Mate credential** if it has a username/password (a RouterOS API
   login works over SSH too) - pushed to Rusted as `mymate-cred-{id}`;
2. else the **default SSH login** from Settings -> pushed as `mymate-default`;
3. else the backup fails with a clear "no SSH credential" message.

Secrets are only ever forwarded to the loopback Rusted API - never logged. The API
token + default SSH password are `Crypt`-encrypted in the `settings` table and never
returned by the API (only `*_set` flags).

## Using it

- **Enable per device** - device inspector -> *Config backups* -> *Enable backups*. The
  driver is auto-suggested from the vendor; override it in the dropdown. Unknown vendors
  must pick a driver before enabling.
- **Back up now** - same panel; queues a `RunDeviceBackupJob` on the isolated `backup`
  queue. Status badge goes *Backing up... -> Backed up / Unchanged / Failed*.
- **History / config** - the panel lists recent runs (time - size - commit) and *View
  config* shows the latest stored config (read-through from Rusted).
- **Nightly** - every backup-enabled device is captured at 02:30 (needs `schedule:work`
  or a cron entry for `php artisan schedule:run`).

Writes (enable/driver/run) are **admin-only**;
read-only operators still see status/history and can view configs.

## Verify against live gear

The test fleet is RouterOS (`mikrotik_routeros`, `/export`), so backups are testable end
to end:

```sh
# 1. engine healthy
curl -s http://127.0.0.1:8410/healthz

# 2. enable + back up DMZ1 (id from the devices list), then watch it land
php artisan mymate:backup:run --device=<DMZ1_id>
php artisan horizon:list        # or tail storage/logs/mymate-*.log for "backup: ..."

# 3. confirm the config was captured + committed
curl -s -H "Authorization: Bearer $RUSTED_API_TOKEN" \
  http://127.0.0.1:8410/api/devices/mymate-<DMZ1_id>/history | jq .
sudo -u rusted git -C /var/lib/rusted/backups log --oneline | head
```

**Integrity:** re-running a backup on an unchanged device must report `unchanged` and
create **no** new git commit (Rusted normalises volatile lines for change-stable diffs).
A first successful run reports `success`/`ok` and a new commit hash - surfaced in the
inspector as the short commit next to the run.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Inspector: "engine isn't configured yet" | Set URL + token in Settings, or `RUSTED_API_URL`/`RUSTED_API_TOKEN` in `.env` + `config:clear`. |
| *Test connection* fails | `systemctl status rusted-api`; `curl http://127.0.0.1:8410/healthz`; check the token matches `config.toml`. |
| Backup -> *Failed*, "no SSH credential" | Attach a RouterOS credential to the device, or set a default SSH login in Settings. |
| Backup -> *Failed*, SSH errors | SSH (22) reachable? login valid? driver correct for the platform? |
| Enable rejected, "pick a driver" | Vendor unknown - choose one in the driver dropdown before enabling. |

## Scope note

This first cut supports **trigger - history - view latest config**. A true *diff between
versions* isn't exposed because Rusted's HTTP API only serves the **latest** config
(`GET /api/devices/{name}/config`) - the git history holds every version but isn't surfaced
per-commit over the API. Adding a `?commit=` param (and a diff endpoint) to Rusted is a
small upstream Go change and the natural follow-up.
