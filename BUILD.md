# Building and running from source

This is for people who want to hack on My Mate or build the packages themselves. If you just
want to run it, use one of the install methods in the [README](README.md) instead.

Everything below assumes Debian 13 or a recent Ubuntu. Other distros work but you're on your
own for package names.

## What you need

```bash
sudo apt-get install -y snmp mtr-tiny postgresql redis-server composer \
  php8.4-cli php8.4-pgsql php8.4-redis php8.4-snmp php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-curl php8.4-intl
```

`mtr-tiny` is what the device inspector's Trace button runs (`/usr/bin/mtr --raw`). The
package's `mtr-packet` helper already carries `cap_net_raw`, so there's nothing to setcap.

You also need Node 20 or newer (the NodeSource repo is the easy way to get it). On Debian 13
the `sockets`, `pcntl` and `posix` extensions are already part of `php8.4-cli`, so there's
nothing extra to install for those.

### fping 5.5

This is the one thing you have to build yourself. My Mate needs fping 5.5 because that's the
first version with JSON output (`fping --json`), and the distro packages are older than that.

```bash
sudo apt-get install -y build-essential autoconf automake libtool
curl -fsSL https://github.com/schweikert/fping/releases/download/v5.5/fping-5.5.tar.gz | tar xz
cd fping-5.5 && ./configure && make && sudo make install
sudo setcap cap_net_raw+ep /usr/local/sbin/fping
fping --version   # should say 5.5
```

fping needs raw socket access to send ICMP. The `setcap` line grants that without making it
setuid root. Make sure `/usr/local/sbin` is ahead of any distro fping on the worker's PATH.

## Getting it running

```bash
# 1. dependencies
composer install
npm install

# 2. environment
cp .env.example .env
php artisan key:generate
# then edit .env: point DB_* at your Postgres, set DB_CONNECTION=pgsql, and set
# QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER=redis, BROADCAST_CONNECTION=reverb

# 3. databases
sudo -u postgres psql -c "CREATE ROLE mymate LOGIN PASSWORD 'changeme';" \
  -c "CREATE DATABASE mymate OWNER mymate;" \
  -c "CREATE DATABASE mymate_test OWNER mymate;"

# 4. schema
php artisan migrate

# 5. frontend
npm run build   # or `npm run dev` while you're working on the UI
```

`CREDS` and `.env` are git ignored. Don't commit them.

## The processes

My Mate is a web app plus a few background processes. In development you run them yourself,
in separate terminals:

```bash
php artisan serve          # the app on http://localhost:8000
npm run dev                # Vite dev server for the UI
php artisan reverb:start   # the WebSocket server
php artisan horizon        # the queue workers that do the polling
php artisan mymate:loop    # the loop that dispatches ping/poll work every few seconds
php artisan mymate:agent-hub --host=127.0.0.1 --port=9091  # only if you use remote agents
```

The agent hub is optional - you only need it when testing remote agents (the probe that runs
inside a management network). Agents connect to `ws(s)://<host>/agent`, which nginx proxies to
this hub on `127.0.0.1:9091`; the shipped nginx/supervisor configs
([deploy/nginx/mymate.conf](deploy/nginx/mymate.conf),
[deploy/supervisor/mymate.conf](deploy/supervisor/mymate.conf)) already wire it up for a
non-dev install.

Open http://localhost:8000. One thing that trips people up: after you change any PHP, the
long running processes (Horizon, the loop, Reverb, the agent hub) keep running the old code
because they booted the framework once and hold it in memory. `artisan serve` and php-fpm
reload on their own, the others don't. Restart them, or you'll swear a change didn't take.

`php artisan mymate:loop --once` runs a single sweep, which is handy for testing. `--discover`
re-walks interfaces, `--scan` kicks off discovery for any subnet that's due, and `--partitions`
rolls the history partitions forward.

The engine writes its own log to `storage/logs/mymate.log`. That's the place to look to see
what's polling and what failed. Failures record the device, ip, method and error, never the
credentials.

## Configuration

Sensible defaults live in `config/mymate.php`. Override anything in `.env`. These are the ones
worth knowing about:

| Variable | Default | What it does |
|---|---|---|
| `MYMATE_PING_INTERVAL` | `5` | Seconds between up/down sweeps |
| `MYMATE_POLL_INTERVAL` | `12` | Seconds between throughput polls |
| `MYMATE_DISCOVER_INTERVAL` | `600` | Seconds between interface re-discovery |
| `MYMATE_POLL_SHARDS` | `16` | Throughput batch jobs per tick, raise it as the fleet grows |
| `MYMATE_BROADCAST_MAX_IFACES` | `500` | Cap on interfaces per WebSocket update |
| `MYMATE_DISCOVERY_SCAN_INTERVAL` | `3600` | Default per subnet scan cadence, overridable per subnet |
| `MYMATE_DISCOVERY_MAX_HOSTS` | `4096` | Most hosts expanded from one subnet, a safety cap |
| `MYMATE_HISTORY_RETENTION_DAYS` | `14` | How many days of history to keep |
| `MYMATE_SNMP_TIMEOUT_US` | `1000000` | SNMP timeout in microseconds, kept short to fail fast |
| `MYMATE_LOG_LEVEL` | `info` | Set to `debug` for per tick heartbeats |

The device secrets the seeder reads (`MYMATE_DEVICE_USER`, `MYMATE_DEVICE_PASS`,
`MYMATE_SNMP_COMMUNITY`) come from `CREDS`.

## Running it for real (from source)

If you're deploying from source rather than the package, run the background processes under a
supervisor. Cron can't do it, it can't hold a socket open and its one minute floor is too
coarse for a five second loop. There's a config to start from at
[deploy/supervisor/mymate.conf](deploy/supervisor/mymate.conf).

```bash
sudo apt-get install -y supervisor
sudo systemctl enable --now supervisor
sudo cp deploy/supervisor/mymate.conf /etc/supervisor/conf.d/mymate.conf
sudo mkdir -p /var/log/mymate
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

The shipped config runs as `www-data`. If you use a different user, chown `storage/` and
`bootstrap/cache/` to it and give fping raw sockets with `setcap` as above.

A couple of things to keep in mind:

- Run exactly one `mymate:loop`. It only pushes jobs onto the queues, Horizon does the work.
  You scale by adding Horizon workers, not more loops. A duplicate loop is harmless because of
  the per device locks, but one is cleaner.
- Serve the web tier with nginx and php-fpm, not `artisan serve` (that's for development).
  Point your load balancer health check at `GET /api/health`, which returns 200 when Postgres
  and Redis both answer and 503 otherwise.

## Tests

The backend tests run against the `mymate_test` database (Postgres, not sqlite, because the
schema uses a plpgsql trigger).

```bash
php artisan test
npx tsc --noEmit
```

Every backend change should come with its tests in the same change.

## Building the distributables

The `.deb` and the Proxmox LXC template are built from `deploy/build/`. See
[deploy/build/README.md](deploy/build/README.md) for the details, but the short version is:

```bash
deploy/build/build-deb.sh      # -> dist/mymate_<version>_amd64.deb
deploy/build/build-lxc.sh      # -> dist/mymate_<version>_<distro>_amd64.tar.zst (needs the .deb first)
```

The remote agent is a Go binary. Build all the target platforms with:

```bash
cd agent && ./build.sh         # -> agent/dist/*.tar.gz + checksums
```

The Docker image is built from the `Dockerfile` in the repo root:

```bash
docker build -t athenanetworks/mymate:dev .
```

CI does all of this on a tag and attaches the results to the GitHub release.

## Cutting a release

A release is a git tag (`vX.Y.Z`); pushing it triggers the CI workflow above,
which builds everything and creates the GitHub release. Before you tag:

1. **Update [CHANGELOG.md](CHANGELOG.md).** Rename the `Unreleased` section to the
   new version with today's date, and add the compare link at the bottom. Keep
   entries grouped under Added / Changed / Fixed and written for a human deciding
   whether to upgrade. If you've been adding notes under `Unreleased` as you merged
   changes there's nothing to reconstruct; otherwise `git log <last-tag>..HEAD` is
   the source of truth for what to write.
2. **Bump the `VERSION` file** (repo root) to the same number. The build stamps this
   into the package and the in-app update check compares against it, so it has to
   match the tag.
3. Commit those two, then tag: `git tag vX.Y.Z && git push --tags`.

## Layout

```
app/                Laravel backend (Models, Enums, Actions, Services, Jobs, Http, Console)
resources/js/       React frontend, organised by feature
database/           migrations, seeders, factories
config/mymate.php   poll intervals, OIDs, timeouts
agent/              the remote probe (Go)
deploy/             packaging (build), TLS helper (ssl), supervisor config
docker/             the container's nginx, supervisor and entrypoint
```

The backend leans on a few conventions: Actions for operations, Services for the SNMP/RouterOS
integrations and computation, thin Jobs, and schema changes only ever through migrations. The
frontend is organised by feature with no barrel files.
