# Sales demo (mymate.network)

The customer-facing demo is the **real app** - same code, same UI, same Reverb/WebSocket
pipeline - fed 100% synthetic data. Nothing is stubbed in the frontend; a separate
environment runs a simulator that mirrors what the poll loop would do with real gear.

## Architecture

One codebase (`/opt/mymate`), two environments on the same host:

| | Production | Demo |
|---|---|---|
| Site | `mymate.as135559.net.au` (:80) | `mymate.network` -> nginx :8090 |
| `APP_ENV` | `production` (`.env`) | `demo` (`.env.demo`) |
| Database | `mymate` | `mymate_demo` |
| Reverb | :8080 | :8081 |
| Workers | Horizon + `mymate:loop` | none - sync queue, simulator only |

Demo pieces:

- **`.env.demo`** - loaded because the demo processes run with `APP_ENV=demo`
  (nginx passes it via fastcgi, supervisor sets it on the daemons). `MYMATE_DEMO=true`
  makes the blade inject a meta tag + the read-only viewer credentials so the SPA
  auto-logs-in as **Demo Viewer** (non-admin -> the whole app is read-only for it).
- **`deploy/nginx/mymate-sales.conf`** - serves `/opt/mymate/public` on :8090 and
  proxies `/app` (WebSocket) to the demo Reverb on :8081.
- **`deploy/supervisor/mymate-demo.conf`** - two daemons:
  `mymate-demo-reverb` (Reverb on :8081) and `mymate-demo-sim` (`mymate:demo --run`).
- **`mymate:mock`** - seeds the "Mock Lab" topology: 8 devices / 7 links styled as a
  small ISP tree, with `monitored=false` and RFC 5737 TEST-NET-2 management IPs
  (198.51.100.x) so the real ping/poll loops never touch them.
- **`mymate:demo`** - the demo driver (see below).

## `mymate:demo`

- **`--seed`** (idempotent, re-run any time):
  - creates/updates the read-only **Demo Viewer** account from `mymate.demo.email/password`;
  - ensures the Mock Lab topology exists and is the **default map** (drops the empty
    stock "Main" map so the demo never opens on a blank canvas);
  - seeds the two alert policies (device-down, link capacity) and a few historical
    outages so the Alerts/Outages views aren't blank;
  - **backfills 24h of per-minute history** for every mock device - throughput
    (`interface_samples`), CPU/mem/temp (`device_metric_samples`) and ping
    latency/loss/jitter (`ping_samples`) - so the inspector charts are full on first
    open. The backfill uses the same sine-based generators as the simulator, keyed on
    epoch seconds, so live samples continue the backfilled series without a seam.
    Re-running replaces the window.
- **`--run`** - the simulator daemon. Every tick (`mymate.demo.tick`, default 3s) it:
  - synthesises smooth per-interface util/bps (sized against each **link's** effective
    speed so link util never blows past 100%), per-device cpu/mem/temp, and per-device
    ping RTT/loss/jitter (down devices: 100% loss, no RTT - like the real ping loop);
  - occasionally flaps a device up/down (`mymate.demo.flip_chance`), opening/closing
    outages and re-evaluating alerts;
  - persists live columns, appends history samples, and broadcasts the same events the
    real poll loop would (`InterfaceUtilUpdated`, `DeviceMetricsUpdated`,
    `DeviceStatusChanged`) so the map/charts move live.
  - History inserts are **self-healing**: on failure (usually a missing day partition -
    the daemon outlives the create-ahead window) they roll the partitions forward via
    `ManageHistoryPartitions` and retry once. Still best-effort overall - history never
    breaks a tick.
- **`--run --once`** - single tick and exit (used by tests).
- **`--clear`** - removes the viewer + mock topology.

## Deploying changes to the demo

The demo shares the production build, but **has its own database** - it is easy to
migrate prod and forget the demo. After deploying backend changes:

```bash
APP_ENV=demo php artisan migrate --force        # <- the step that gets forgotten
APP_ENV=demo php artisan mymate:demo --seed     # refresh viewer/topology/backfill (idempotent)
sudo supervisorctl restart mymate-demo-reverb mymate-demo-sim
```

The daemons run whatever code was loaded when they started - like Horizon, they must be
restarted to pick up new code or `.env.demo` changes.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Map shows "Nothing on this map yet" but the header counts devices | `/api/maps/{id}` is 500ing - almost always **pending migrations on `mymate_demo`** (the endpoint touches newer tables like `map_notes`). Run the migrate step above; check `storage/logs/laravel-*.log` for `demo.ERROR`. |
| Status pill shows **offline** | The browser WebSocket is being refused. Check `REVERB_ALLOWED_ORIGINS` in `.env.demo` includes the sales domain (`mymate.network,www.mymate.network`) - Reverb accepts the handshake then rejects a bad origin with pusher error **4009**. Also check `mymate-demo-reverb` is running. |
| Charts frozen / stop at some past date | The sim daemon outlived its partition window (fixed - inserts now self-heal) or is down. `sudo supervisorctl status`, then restart `mymate-demo-sim`; check `storage/logs/demo-sim.log`. |
| Inspector says "No history yet" | Re-run `APP_ENV=demo php artisan mymate:demo --seed` to backfill 24h of history. |
| Demo auto-login broken | `MYMATE_DEMO=true` missing, or the viewer account is gone - re-run `--seed`. |
