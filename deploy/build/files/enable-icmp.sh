#!/bin/sh
# Enable ICMP for fping run by the non-root `mymate` service user - My Mate's up/down
# monitoring depends on it. Two mechanisms, most-portable first:
#
#   1. Unprivileged ICMP "ping sockets" via net.ipv4.ping_group_range. This is a per-network-
#      namespace sysctl, so it works on bare metal, VMs AND unprivileged LXC (where raw
#      sockets are usually unavailable). No capability needed.
#   2. CAP_NET_RAW on the fping binary (raw sockets) - the classic mechanism, for hosts where
#      the ping-socket sysctl can't be set.
#
# Exit 0 if fping can actually ping afterwards, 1 if neither mechanism works. Called by the
# package postinst (which treats a non-zero exit as a hard install failure) and by the
# first-boot service for prebuilt images.
set -u
FPING=/usr/local/sbin/fping

icmp_works() { su -s /bin/sh mymate -c "$FPING -c1 -t300 127.0.0.1 >/dev/null 2>&1"; }

# 1. Unprivileged ping sockets. The persistent drop-in (/etc/sysctl.d/99-mymate.conf) is
#    applied by systemd on boot; set it live here too so it takes effect immediately.
sysctl -w net.ipv4.ping_group_range="0 2147483647" >/dev/null 2>&1 \
    || echo "0 2147483647" > /proc/sys/net/ipv4/ping_group_range 2>/dev/null || true

setcap -r "$FPING" 2>/dev/null || true      # start from a clean slate
icmp_works && exit 0

# 2. Fall back to the raw-socket capability.
setcap cap_net_raw+ep "$FPING" 2>/dev/null || true
icmp_works && exit 0

# Neither worked. Leave the cap OFF so the binary at least execs (a cap it can't honour
# would make it un-exec'able), and report failure to the caller.
setcap -r "$FPING" 2>/dev/null || true
exit 1
