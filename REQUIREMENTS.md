# Requirements and sizing

My Mate runs comfortably on modest hardware. The numbers below are practical guidance, not hard
limits - the honest way to size is to start on the small end and watch disk/CPU/memory for a week,
since it depends on your fleet size, poll cadence, and how long you keep history.

Some of these figures come from a real production instance monitoring **~2700 devices** (thanks
to the operator who shared them in #16).

## What drives resource use

- **Devices + poll cadence** drive CPU (fping sweeps, SNMP/RouterOS polls) and the write rate.
- **History retention** drives disk. Interface throughput, device metrics (cpu/mem/temp), ping
  latency and custom sensors are written every poll into daily-partitioned tables, and partitions
  older than the retention window are dropped automatically. Default retention is **14 days**
  (Settings -> Engine, `history.retention_days`). Disk use plateaus once you reach that window.
- **Redis** is a transient broker (queues, cache, live broadcasting). It holds no durable data -
  everything of record is in PostgreSQL - so it is provisioned with persistence off (see below).

## Rough sizing

| Fleet | CPU | RAM | Disk (/opt + Postgres) |
|-------|-----|-----|------------------------|
| up to ~250 devices | 2 cores | 4 GB | 20 GB |
| ~250-1000 | 2-4 cores | 4-8 GB | 40 GB |
| ~1000-3000 | 4-6 cores | 8-12 GB | 60-100 GB |

Reference point (~2700 devices, ~10 days uptime, default 14-day retention): load average ~0.8-1.15
on 6 cores (4 would be fine), ~8 GB RAM in use, and disk sitting around `/opt` 15 GB, PostgreSQL
4.5 GB, Redis ~5 GB. Disk was still climbing toward the 14-day plateau at that point.

## Disk

Give the data room and, ideally, keep it off the OS disk so it is easy to grow later. The operator
in #16 puts `/opt`, PostgreSQL and Redis on separate LVM volumes for exactly this - recommended if
you run at scale.

- **`/opt/mymate`** - the app plus its history tables. This is the main grower; size it for
  `~(daily history) x (retention_days)` plus headroom. Lower `history.retention_days` to save disk,
  raise it for longer trends.
- **PostgreSQL** (`/var/lib/postgresql`) - device/interface/link metadata plus the partitioned
  sample tables. Grows with fleet size and retention.
- **Redis** (`/var/lib/redis`) - see below.

If any of these fills up, monitoring degrades. Watch them, or split them onto their own volumes.

## Redis

My Mate uses Redis only as a transient broker, so **persistence is turned off** by the packaged
installs - no RDB snapshots, no AOF, and a failed background save never blocks writes. This avoids
the two problems large fleets hit with the stock Redis defaults: the RDB dump filling the disk, and
`redis-check-rdb` / the snapshot fork spiking memory (#16). The trade-off is that Redis data (queued
jobs, cache, sessions) is lost on a Redis restart, which is fine here - the poll loop re-populates,
and nothing of record lives there.

The packaged installs also set a **memory ceiling** (`maxmemory` at 40% of RAM, `maxmemory-policy
allkeys-lru`) so Redis can never grow until the kernel OOM-kills it and takes monitoring down with
it. Because nothing in Redis is durable, evicting under pressure is safe - the poll loop rebuilds
its working set on the next tick. Live map updates (interface load, device metrics, up/down) are
broadcast inline rather than queued, so a large fleet's per-tick update stream no longer piles up
in Redis waiting to be delivered - the single biggest source of runaway Redis memory before.

The `.deb`/LXC installs apply this automatically; the Docker Compose Redis service is started with
the same flags. If you run your own Redis, the equivalent settings are:

```
save ""
appendonly no
stop-writes-on-bgsave-error no
maxmemory <~40% of RAM>
maxmemory-policy allkeys-lru
```

If Redis memory ever climbs toward the ceiling, check for a queue backlog (`redis-cli INFO
keyspace`, and the `*queues*` list lengths) - it usually means the poll workers can't keep up, so
raise `mymate.poll.shards` and the `poll` worker count rather than just giving Redis more RAM.

## CPU and memory

- **CPU**: 4 cores is a good target for a few thousand devices; scale up if you shorten the poll
  interval or add a lot of SNMP sensors. Load is dominated by the poll workers and history writes.
- **Memory**: 8 GB is comfortable at a few thousand devices. Leave headroom and keep some swap.
- ICMP up/down monitoring needs raw-socket capability - the installer sets this up and refuses to
  install if the host can't do it.
