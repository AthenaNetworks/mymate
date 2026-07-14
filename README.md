# My Mate

A modern, web based replacement for MikroTik's The Dude. It pings every device for
up/down, polls interface throughput, lets you link devices interface to interface, and
draws a live map where the links change colour from green through to red as they get busy.

The Dude only has a Windows client and it hasn't moved in years. My Mate keeps the good
parts (the interactive map, fast up/down, live link colouring) and delivers them as a clean
web app you can run anywhere.

**Free and open source, MIT licensed.** No device limits, no license keys, nothing to unlock.

## What it does

- Up/down monitoring with a batched fping sweep every few seconds, pushed live to the map.
- Interactive topology map. Devices are draggable nodes and the layout sticks.
- Per interface throughput over SNMP (64 bit counters) or the RouterOS API, picked per device.
- Link colouring: draw a link, bind each end to an interface, and the edge colours by
  utilisation in real time (grey when down).
- Real time updates over WebSockets, no client side polling.
- Scales from a handful of devices to thousands: sharded dispatch, batched polling, bulk
  writes, per device failure isolation.
- Subnet auto discovery with a review queue, so nothing gets added without you approving it.
- Recent history charts per link, kept in time partitioned tables.
- Device health at a glance: CPU, memory and temperature per device (SNMP or the RouterOS
  API), shown right on the map tiles and charted in the inspector.
- Ordered firmware upgrades: pick the devices and it works out the safe order (furthest out
  first) and waits for each one to come back online before it touches the next, so a reboot
  never cuts the path to gear that's still pending.
- Config backups over SSH, driven by the bundled Rusted engine which sets itself up on
  install. Back up on a schedule you choose, then browse every stored version and diff them
  from the Backups page. For MikroTik (which won't hand its config over the API) it can
  install an SSH key over the API so key based backups just work.
- A heads up in Settings when a newer release is out.
- Login with an in app operator list and a read only viewer tier.
- Alerting (email, Slack, Teams, Messenger), outage history, multiple maps, and a
  wallboard/dashboard mode for the NOC screen.

## Install

Pick whichever fits how you run things. All three give you the same app. Grab the packages
and templates from [Releases](../../releases).

### Debian / Ubuntu package

The easy path. One package pulls in PostgreSQL, Redis, nginx and php-fpm, provisions the
database, sets up the background services, and leaves you a working install. Debian 13 or
Ubuntu 22.04 and newer.

```sh
sudo apt install ./mymate_<version>_amd64.deb
sudo -u mymate php /opt/mymate/artisan mymate:user:create --admin
```

It comes up on HTTPS straight away with a self signed certificate, so your browser will warn
once (that's expected, click through). When you're ready for a real certificate, run
`sudo mymate-ssl setup` and it will walk you through it.

### Proxmox LXC

Import the LXC template and start the container. It gives itself a fresh identity and
configures everything on first boot, then serves the console. Nothing else to do.

### Docker Compose

The image is on Docker Hub as `athenanetworks/mymate`. The compose file in this repo brings
up the app together with Postgres and Redis:

```sh
docker compose up -d
docker compose exec app php artisan mymate:user:create --admin
# open https://localhost:9443   (self-signed cert, so the browser warns once)
```

It serves HTTPS on port 9443 by default with a self-signed certificate, on all interfaces.
Change the port with `MYMATE_HTTPS_PORT`. If you'd rather terminate TLS at a reverse proxy or
a Cloudflare Tunnel, uncomment the HTTP port in `docker-compose.yml` and expose that instead
(the app reads `X-Forwarded-Proto`). Set your own `APP_KEY` and `REVERB_APP_SECRET`, and point
`APP_URL` at the address you actually browse to, before you rely on it.

## Upgrading

Your data, `.env` and configuration are preserved across upgrades. Every method runs the
database migrations and restarts the background services for you - there are no manual steps.

### Debian / Ubuntu package

Install the newer `.deb` over the existing install. It briefly holds the console in
maintenance mode while it clears stale caches, migrates and restarts every service:

```sh
sudo apt install ./mymate_<new-version>_amd64.deb
```

### Proxmox LXC

An LXC runs the Debian package inside the container, so you upgrade it exactly like the
package - copy the new `.deb` into the container and install it. Don't import a fresh
template; that would be a new, empty instance.

```sh
# from the Proxmox host (replace <vmid>)
pct push <vmid> mymate_<new-version>_amd64.deb /tmp/mymate.deb
pct exec <vmid> -- apt install -y /tmp/mymate.deb
```

Or `pct enter <vmid>` and run the package step inside the container.

### Docker Compose

Pull the new image and recreate the container. The entrypoint waits for the database, runs
migrations, clears stale caches, then starts the services:

```sh
docker compose pull
docker compose up -d
```

Pin the image tag you want in `docker-compose.yml` (or track `:latest`) before pulling. Keep
`/etc/rusted` and `/var/lib/rusted` as named volumes so your backup history survives the
recreate.

## First login

There is no public sign up. The first account you create is always an admin:

```sh
mymate:user:create --admin
```

After that, admins manage accounts inside the app under Settings. Admins can change anything,
everyone else is read only.

## HTTPS

The packaged and LXC installs are HTTPS on day one with a self signed certificate. To move to
something trusted, `sudo mymate-ssl setup` covers a Cloudflare Tunnel (no open ports), your
own reverse proxy, Let's Encrypt, or a certificate you already have. It sorts out the console
and both WebSocket endpoints in one go. See [deploy/ssl/README.md](deploy/ssl/README.md).

## Remote agents

If the network you want to watch is out of band, you don't have to expose it. Run a small
[agent](agent/README.md) inside it (a single Go binary that runs on a VM, a container or a
Raspberry Pi). It dials an outbound WebSocket back to the app and polls and discovers devices
locally. Enrol one with `php artisan mymate:agent:create "<name>"` and assign devices or scan
ranges to it in the console.

## Documentation

| Doc | What |
|---|---|
| [BUILD.md](BUILD.md) | Running from source and building the packages/image yourself |
| [deploy/ssl/README.md](deploy/ssl/README.md) | HTTPS: Cloudflare Tunnel, reverse proxy, Let's Encrypt, own cert |
| [deploy/rusted/README.md](deploy/rusted/README.md) | Config backups: the Rusted engine, how it's provisioned, and credentials |
| [agent/README.md](agent/README.md) | The remote agent: deploy, configure, discover |

## Contributing

Issues and pull requests are welcome. Run `php artisan test` and `npx tsc --noEmit` before
opening a PR. CI runs both plus a package build on every push. If you want to hack on it, start
with [BUILD.md](BUILD.md).

## Contributors

- [@JoshFinlayAU](https://github.com/JoshFinlayAU) - Josh
- [@genuwin14](https://github.com/genuwin14) - Kristian
- [@marki1212](https://github.com/marki1212) - Mark

## License

[MIT](LICENSE), Athena Networks. Free to use, modify and distribute.
