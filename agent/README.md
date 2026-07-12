# My Mate remote agent

A lightweight **probe** you run inside a management network. It dials an outbound encrypted
WebSocket to the central My Mate app and polls/discovers devices locally - so an out-of-band
(SaaS) install never needs the management network exposed to the internet.

A single **static Go binary** (no runtime dependencies) - the same build runs on a VM, a
container, or a Raspberry Pi.

## Platforms

| OS | Arch | Target |
|----|------|--------|
| Linux  | amd64 | servers / VMs / containers |
| Linux  | arm64 | Raspberry Pi 4/5 (64-bit), ARM servers |
| Linux  | arm (v7) | older 32-bit Raspberry Pi |
| macOS  | arm64 | Apple Silicon |
| macOS  | amd64 | Intel Mac |

## Install

1. On the app, enrol the agent and copy its token:
   ```sh
   php artisan mymate:agent:create "Site A"
   ```
2. On the agent host, download the archive for your platform from the release, unpack it, and:
   ```sh
   sudo ./install.sh --url https://mymate.example.com --token mma_xxxxx
   ```

That installs the binary to `/usr/local/bin/mymate-agent` and registers a service that
starts at boot and restarts on failure - **systemd** on Linux, **launchd** on macOS.

- Linux logs: `journalctl -u mymate-agent -f`
- macOS logs: `tail -f /var/log/mymate-agent.log`

## Configuration

Environment variables (see `packaging/agent.env.example`):

| Var | Required | Notes |
|-----|----------|-------|
| `MYMATE_URL` | yes | app URL; `http(s)://` is rewritten to `ws(s)://` + `/agent` |
| `MYMATE_AGENT_TOKEN` | yes | from `mymate:agent:create` |
| `MYMATE_AGENT_NAME` | no | label reported to the server (default: hostname) |
| `MYMATE_AGENT_LOG` | no | `info` (default) or `debug` |
| `MYMATE_AGENT_INSECURE` | no | `1` skips TLS verification (self-signed dev only) |

## Recovery

Two layers: the agent **reconnects in-process** with exponential backoff + jitter if the
link drops, and the **service manager** (systemd `Restart=always` / launchd `KeepAlive`)
restarts the process if it ever exits.

## Build from source

```sh
./build.sh host        # this machine -> bin/mymate-agent
VERSION=v1.0.0 ./build.sh   # all platforms -> dist/*.tar.gz (+ SHA256SUMS)
```

Needs Go 1.25+. CI builds these on every release tag.

## Discovery

The agent also **discovers** devices on its own network. In the console, add a scan range
(Settings -> discovery) and choose *Scan via agent: <name>*; the agent ping-sweeps that CIDR,
probes responders with the credential pool (SNMP, then RouterOS), and the results land in the
usual review queue - so devices behind the agent are found from the inside, never scanned
across the internet.
