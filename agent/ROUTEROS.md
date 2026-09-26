# Running the agent in a RouterOS container

The site's own MikroTik router can host the My Mate agent, much like The Dude uses a site
router to do its polling. RouterOS 7 can run OCI containers, and the agent is a single
static binary, so the agent image is tiny (about 7 MB per arch) and needs no state on disk.

> **Status: untested on real RouterOS hardware.** The image itself is built and checked on
> Linux (it starts, connects over TLS, pings via a raw socket as root, stops cleanly on
> SIGTERM). The RouterOS side below is written from MikroTik's container documentation
> (https://help.mikrotik.com/docs/spaces/ROS/pages/84901929/Container) and has not been run
> end to end on a router yet. RouterOS has renamed a few container properties between 7.x
> releases, so if a command below is rejected, check `/container/add ?` (or the docs for your
> version) for the current name. If you get it going, or hit a snag, please open an issue so
> this page can be corrected.

## What you need

- RouterOS **7.x** on hardware with an **arm, arm64 or x86 (amd64)** CPU. MIPS, SMIPS, MMIPS,
  PowerPC and TILE boards can't run containers at all. Check with
  `/system/resource/print` (look at `architecture-name`).
- The matching **container** package installed (it's in the "Extra packages" zip for your
  RouterOS version and arch on mikrotik.com/download).
- A little storage. The image is ~7 MB, it unpacks to about the same. Internal flash is
  usually fine for this, a USB stick or disk works too.
- A working clock (NTP client on). The agent talks TLS to your server, and a router that
  thinks it's 1970 will fail certificate checks.
- The agent image tarball for your arch, from the GitHub release:

  | `architecture-name` | Release asset |
  |---|---|
  | `arm64` | `mymate-agent-image_<version>_linux_arm64.tar` |
  | `arm` | `mymate-agent-image_<version>_linux_armv7.tar` |
  | `x86_64` (incl. CHR) | `mymate-agent-image_<version>_linux_amd64.tar` |

  Checksums are in `mymate-agent-image_<version>_SHA256SUMS`.

## 1. Enrol the agent in My Mate

In My Mate go to **Settings -> Agents**, type a name (eg "Site A router") and enrol it. The
token is shown **once**, copy it now. (Or on the server: `php artisan mymate:agent:create
"Site A router"`.)

## 2. Enable containers on the router

Install the container package (upload the `.npk` to Files, then reboot), then turn on
container support in device-mode:

```
/system/device-mode/update container=yes
```

RouterOS then wants physical confirmation: press the reset button or power cycle the router
within 5 minutes (which one depends on the model, the terminal tells you). Check it took:

```
/system/device-mode/print
```

## 3. Give the container a network

The container gets a virtual ethernet (veth) on its own little bridge, and the router NATs
it out. Adjust the 172.17.0.0/24 range if it clashes with something you already use.

```
/interface/veth/add name=veth-mymate address=172.17.0.2/24 gateway=172.17.0.1
/interface/bridge/add name=containers
/ip/address/add address=172.17.0.1/24 interface=containers
/interface/bridge/port/add bridge=containers interface=veth-mymate
/ip/firewall/nat/add chain=srcnat action=masquerade src-address=172.17.0.0/24 comment="mymate agent out"
```

Masquerading means the devices you poll see the ping / SNMP / API traffic coming from the
router's own address on that network, so any SNMP community or API allow lists need to allow
the router, not 172.17.0.2.

**Firewall.** The agent needs to reach:

- your My Mate server on 443 (outbound, same as a browser would);
- the devices you want polled (ICMP, SNMP udp/161, RouterOS API 8728/8729);
- the router itself, if you want the router monitored too.

The default config lets forwarded traffic from a non-WAN interface through, so the first two
usually just work. The **input** chain on the default config drops anything not from the LAN
list though, so to poll the router itself either add the bridge to LAN:

```
/interface/list/member/add list=LAN interface=containers
```

or add a narrower accept rule for 172.17.0.2 above the drop. When you add the router as a
device in My Mate, its management IP can be its normal LAN address.

## 4. Upload the image and set the config

Upload the `.tar` for your arch to the router (Winbox / WebFig Files, or
`scp mymate-agent-image_v1.2.0_linux_arm64.tar admin@router:`). If you're using a USB disk,
put it there, eg `usb1/`.

RouterOS needs somewhere to unpack images:

```
/container/config/set tmpdir=tmp
```

Then the environment. `MYMATE_URL` and `MYMATE_AGENT_TOKEN` are required, the rest are
optional. These are the same variables the agent uses everywhere (see [README.md](README.md#configuration)).

```
/container/envs/add list=mymate key=MYMATE_URL value="https://mymate.example.com"
/container/envs/add list=mymate key=MYMATE_AGENT_TOKEN value="mma_paste_your_token_here"
/container/envs/add list=mymate key=MYMATE_AGENT_NAME value="Site A router"
```

Optional extras:

- `MYMATE_AGENT_LOG=debug` for chattier logs while you set it up.
- `MYMATE_AGENT_INSECURE=1` skips TLS verification. Only for a test server with a self
  signed cert, don't leave it on.

On newer 7.x releases the envs property may be called `name=` rather than `list=` (and the
container's `envlist=` below becomes `envlists=`). Use whichever your version accepts.

## 5. Create and start the container

```
/container/add file=mymate-agent-image_v1.2.0_linux_arm64.tar interface=veth-mymate \
    envlist=mymate root-dir=mymate-agent hostname=mymate-agent \
    logging=yes start-on-boot=yes
```

`root-dir` is where the image gets unpacked (put it on `usb1/...` if you're using a disk).
The agent writes nothing to disk, so **no mounts are needed** and nothing is lost when the
container is recreated.

Wait for it to finish extracting (status goes from `extracting` to `stopped`), then start it:

```
/container/print
/container/start 0
```

(use the number `/container/print` shows for it). Check the logs:

```
/log/print where topics~"container"
```

You want to see `mymate-agent starting` and no `disconnected from hub` lines repeating. The
image has no shell, so `/container/shell` won't work on it. That's intentional, it keeps the
image small.

`start-on-boot=yes` brings it back after a reboot. If the connection to the server drops the
agent reconnects by itself with backoff, it doesn't need restarting.

## 6. Check it in My Mate

Back in **Settings -> Agents** the agent should now show **online**, with its round trip
latency, the platform (eg `linux/arm64`) and its version. If the version is older than the
server you'll see an **update** badge.

Then put it to work:

- **Devices:** set the **Agent** field on a device (device form) to this agent and it gets
  polled from the router instead of from the central server.
- **Discovery:** add a scan range and choose **Scan via agent: Site A router**. The sweep
  runs from the router and the results land in the normal review queue.

## Upgrading

The agent has no state, so upgrading is just replacing the container. The envs list stays.

```
/container/stop 0
/container/remove 0
```

Upload the new tar, run the same `/container/add` as above with the new file name, and start
it again. Delete the old tar from Files once it's running.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Agent never shows online, log says `lookup ... no such host` | The container has no DNS. Add `dns=172.17.0.1` to `/container/add` (and `/ip/dns/set allow-remote-requests=yes`, with your input firewall keeping DNS off the WAN), or point it at a public resolver. |
| `x509: certificate has expired or is not yet valid` | The router clock is wrong. Turn on the NTP client. |
| `x509: certificate signed by unknown authority` | Your server uses a self signed or private CA cert. Best fix is a real cert on the server (see deploy/ssl/README.md). For a test box, `MYMATE_AGENT_INSECURE=1`. |
| `hub rejected the token (HTTP 401)` | Wrong or deleted token. Enrol again and update `MYMATE_AGENT_TOKEN`. |
| `hub endpoint not found (HTTP 404)` | `MYMATE_URL` points at the wrong place. It should be the same URL you open My Mate on. |
| Online, but every device it polls is down; log says `cannot open an ICMP socket` | The container isn't allowed to open a raw ICMP socket. The image runs as root and needs `CAP_NET_RAW` (or a `ping_group_range` that includes root). Please open an issue with your RouterOS version and model. |
| Online, devices down, no ICMP error | Routing or firewall. Check the router can reach them and that forward/NAT rules cover 172.17.0.0/24. |

## Building the image yourself

From a checkout of the repo, with Docker buildx (or podman):

```sh
docker buildx build agent --platform linux/arm64 --build-arg VERSION=v1.2.0 \
    --provenance=false --output type=docker,dest=mymate-agent-arm64.tar
```

Swap the platform for `linux/arm/v7` or `linux/amd64` as needed. With podman:

```sh
podman build --platform linux/arm64 --build-arg VERSION=v1.2.0 -t mymate-agent:arm64 agent
podman save --format docker-archive -o mymate-agent-arm64.tar mymate-agent:arm64
```

The same image works on any Docker/podman host too:

```sh
docker load -i mymate-agent-amd64.tar
docker run -d --restart unless-stopped --name mymate-agent \
    -e MYMATE_URL=https://mymate.example.com -e MYMATE_AGENT_TOKEN=mma_xxx \
    mymate-agent:v1.2.0
```

If the log says `cannot open an ICMP socket` there, add `--cap-add NET_RAW`.
