# TLS / HTTPS for My Mate

Everything lives on **one origin**: the web console, the `/app` Reverb WebSocket (live map
colours + up/down toasts) and the `/agent` WebSocket hub the remote agents dial into. So a
TLS setup has to cover all three. The nice side effect: agents connect over
`wss://<domain>/agent`, so once the console is HTTPS the agent tunnel is too.

## Secure by default (day 1)

A fresh install comes up on **HTTPS already**. The installer drops a per-instance self-signed
cert so the console and the agent tunnel are encrypted before anyone logs in. First visit the
browser will moan about the cert not being trusted - that's expected with a self-signed one,
just click through. Point is nothing goes over the wire in the clear.

Once you've got a real hostname/cert, run the wizard and it'll sort the rest:

```
sudo mymate-ssl setup
```

## The four upgrade methods

`mymate-ssl setup` walks you through these; you can also run any directly:

```
sudo mymate-ssl cloudflared    <domain> [tunnel-token]   # 1. Cloudflare Tunnel (preferred)
sudo mymate-ssl reverse-proxy  <domain>                  # 2. behind your own TLS proxy
sudo mymate-ssl letsencrypt    <domain> <email>          # 3. public, Let's Encrypt
sudo mymate-ssl certificate    <domain> <fullchain> <key># 4. public, your own certificate
sudo mymate-ssl self-signed    [host]                    # 0. regenerate the built-in cert
```

Whatever the method, it also runs `php artisan mymate:configure-host <domain> --https`,
which sets `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, and
`SESSION_SECURE_COOKIE=true` in `.env` and restarts the engine so Reverb hands the
browser a `wss://` URL.

| # | Method | TLS terminates | Public ports | Best when |
|---|--------|----------------|--------------|-----------|
| 0 | Self-signed *(default)* | nginx (this box) | 443 | day 1, before you have a domain/cert - encrypted, with a browser trust warning |
| 1 | Cloudflare Tunnel | Cloudflare edge | **none** (outbound only) | the box is out-of-band / behind NAT; you don't want to expose any port |
| 2 | Reverse proxy | your proxy | none on this box | you already run Traefik/nginx/HAProxy/a load balancer |
| 3 | Let's Encrypt | nginx (this box) | 80 + 443 | the box is public and you want a free auto-renewing cert |
| 4 | Own certificate | nginx (this box) | 443 | you have a corporate/wildcard cert to install |

## How HTTPS is detected

- **Methods 0, 3 & 4** - nginx terminates TLS and talks to PHP-FPM with `fastcgi_param HTTPS on`
  (see `nginx-tls.conf.tmpl`). Laravel sees a secure request directly.
- **Methods 1 & 2** - TLS terminates upstream; the request reaches nginx->FPM over plain
  HTTP carrying `X-Forwarded-Proto: https`. The app **trusts proxies** (set in
  `bootstrap/app.php`) so it honours that header - otherwise it would generate `http://`
  URLs and refuse to set the secure session cookie.

  > **Security:** trusting the forwarded proto means your edge (Cloudflare / your proxy)
  > **must overwrite** any client-supplied `X-Forwarded-*` header. Cloudflare does this
  > automatically; a self-hosted proxy must be configured to (the `reverse-proxy` output
  > shows the exact `proxy_set_header` lines). The only direct client of nginx is the
  > local proxy, so this is safe for an appliance.

## Method 1 - Cloudflare Tunnel (preferred)

Out-of-band monitoring without exposing the management network. `cloudflared` dials
**outbound** to Cloudflare; nothing listens publicly on the box.

```
sudo mymate-ssl cloudflared mon.example.com
```

Then in the Cloudflare Zero Trust dashboard -> **Networks -> Tunnels -> Create a tunnel**:
add a **public hostname** `mon.example.com` -> **Service: HTTP -> `http://localhost:80`**.
WebSockets for `/app` and `/agent` traverse the tunnel automatically - no extra config.
Copy the tunnel token it gives you and re-run to install the service:

```
sudo mymate-ssl cloudflared mon.example.com eyJ...tunnel-token...
```

Agents anywhere then connect to `wss://mon.example.com/agent` with their token.

## Method 2 - behind your own reverse proxy

Keep the box on plain HTTP :80 and let your existing TLS proxy front it:

```
sudo mymate-ssl reverse-proxy mon.example.com
```

The command prints the exact upstream requirements (forward `Host`, set
`X-Forwarded-Proto: https`, strip client `X-Forwarded-*`, pass WebSocket upgrades for
`/app` and `/agent`). Point your proxy at `http://<box-ip>:80`.

## Method 3 - public, Let's Encrypt

The box is reachable on 80/443 at a public DNS name:

```
sudo mymate-ssl letsencrypt mon.example.com ops@example.com
```

Obtains a cert via the webroot challenge (served by the existing :80 site), installs the
HTTPS nginx site, and adds a renewal deploy-hook that reloads nginx. Renewal is handled
by certbot's systemd timer.

## Method 4 - public, your own certificate

You already have a certificate (e.g. a wildcard or corporate CA):

```
sudo mymate-ssl certificate mon.example.com /path/fullchain.pem /path/privkey.pem
```

nginx is pointed straight at those files (not copied). To renew, replace the files and
`sudo systemctl reload nginx`.

## Reverting to plain HTTP

Reinstall the plain site and drop the https flags:

```
sudo cp deploy/build/files/nginx-mymate.conf /etc/nginx/sites-available/mymate
sudo systemctl reload nginx
sudo -u mymate php /opt/mymate/artisan mymate:configure-host mon.example.com   # no --https
```

## Troubleshooting

- **Console loads but login 401s / redirects loop** - `APP_URL`/`SANCTUM_STATEFUL_DOMAINS`
  don't match the browsed hostname. Re-run `mymate:configure-host <domain> --https`.
- **Live map stops updating over HTTPS** - the browser tried `ws://` on an `https://` page
  (mixed content). Confirm the engine restarted so Reverb advertises `wss://` (the helper
  does this); hard-refresh.
- **Agent won't connect** - it must use `wss://<domain>/agent` (matching the cert's name)
  with a valid Bearer token. For a self-signed cert set `MYMATE_INSECURE=1` on the agent
  (dev only). Check the hub: `journalctl -u mymate-agent-hub`.
