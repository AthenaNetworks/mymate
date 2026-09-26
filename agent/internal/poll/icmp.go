package poll

import (
	"log/slog"
	"net"
	"os"
	"sync"
	"sync/atomic"
	"time"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

// pingOnce sends one ICMP echo to host and returns the round-trip time (ms) and whether an echo
// reply came back within timeout. It opens its own socket per call, which keeps concurrent pings
// apart on a ping socket (the kernel demuxes by id) but NOT on a raw one, see below.
//
// It prefers an unprivileged "ping" datagram socket (udp4 - works when
// net.ipv4.ping_group_range permits the user), falling back to a raw socket (needs
// CAP_NET_RAW, which the shipped systemd unit grants). Same privilege model as fping.
//
// The raw path matters more than it looks: as root inside a container (RouterOS, docker) the
// ping socket is usually refused by ping_group_range, so we end up on the raw socket. A raw
// socket sees every echo reply arriving at the box, not just ours, so replies are matched on
// sender + id + seq below. Without that one live host would mark every concurrent ping up.
func pingOnce(host string, timeout time.Duration) (float64, bool) {
	dst, err := net.ResolveIPAddr("ip4", host)
	if err != nil {
		return 0, false
	}

	conn, err := icmp.ListenPacket("udp4", "0.0.0.0")
	unprivileged := true
	if err != nil {
		conn, err = icmp.ListenPacket("ip4:icmp", "0.0.0.0")
		unprivileged = false
	}
	if err != nil {
		// Otherwise every device just reads as down with no clue why. Once is plenty.
		noICMPWarn.Do(func() {
			slog.Error("cannot open an ICMP socket, every ping will fail: needs CAP_NET_RAW or a net.ipv4.ping_group_range that covers this user", "error", err)
		})
		return 0, false
	}
	defer conn.Close()

	id := os.Getpid() & 0xffff
	seq := int(pingSeq.Add(1) & 0xffff)
	msg := icmp.Message{
		Type: ipv4.ICMPTypeEcho,
		Body: &icmp.Echo{ID: id, Seq: seq, Data: []byte("mymate-agent")},
	}
	wb, err := msg.Marshal(nil)
	if err != nil {
		return 0, false
	}

	var addr net.Addr = dst
	if unprivileged {
		addr = &net.UDPAddr{IP: dst.IP} // ping sockets address by UDP
	}

	deadline := time.Now().Add(timeout)
	_ = conn.SetDeadline(deadline)
	start := time.Now()
	if _, err := conn.WriteTo(wb, addr); err != nil {
		return 0, false
	}

	rb := make([]byte, 1500)
	for time.Now().Before(deadline) {
		n, peer, err := conn.ReadFrom(rb)
		if err != nil {
			return 0, false // timeout / error -> unreachable
		}
		rm, err := icmp.ParseMessage(1, rb[:n]) // 1 = ICMPv4 protocol number
		if err != nil {
			continue
		}
		// On a ping socket the kernel rewrites the id to the socket's own and only hands us
		// our replies, so the id check is for the raw path only.
		if isOurEchoReply(rm, peer, dst.IP, id, seq, !unprivileged) {
			return float64(time.Since(start).Microseconds()) / 1000.0, true
		}
	}
	return 0, false
}

// pingSeq hands every echo its own sequence number so concurrent pings on raw sockets (which
// all share the process id as their echo id) can tell their replies apart.
var pingSeq atomic.Uint32

var noICMPWarn sync.Once

// isOurEchoReply reports whether a received message is the reply to the echo we sent to dst.
func isOurEchoReply(rm *icmp.Message, peer net.Addr, dst net.IP, id, seq int, checkID bool) bool {
	if rm == nil || rm.Type != ipv4.ICMPTypeEchoReply {
		return false
	}
	echo, ok := rm.Body.(*icmp.Echo)
	if !ok || echo.Seq != seq || (checkID && echo.ID != id) {
		return false
	}
	var from net.IP
	switch a := peer.(type) {
	case *net.IPAddr:
		from = a.IP
	case *net.UDPAddr:
		from = a.IP
	default:
		return false
	}
	return from.Equal(dst)
}

// ping reports only reachability - used by discovery, where latency doesn't matter.
func ping(host string, timeout time.Duration) bool {
	_, ok := pingOnce(host, timeout)
	return ok
}

// pingStats sends `count` echoes and summarises them the way the central fping sweep does: up if
// any replied, plus the average rtt over the replies, loss %, and jitter (the mean absolute
// difference between consecutive rtts). A fully-missed host is down with 100% loss and no rtt.
func pingStats(host string, timeout time.Duration, count int) (up bool, rttMs, lossPct, jitterMs float64) {
	if count < 1 {
		count = 1
	}
	rtts := make([]float64, 0, count)
	for i := 0; i < count; i++ {
		if rtt, ok := pingOnce(host, timeout); ok {
			rtts = append(rtts, rtt)
		}
	}

	got := len(rtts)
	lossPct = float64(count-got) / float64(count) * 100
	if got == 0 {
		return false, 0, 100, 0
	}

	var sum float64
	for _, r := range rtts {
		sum += r
	}
	rttMs = sum / float64(got)

	if got > 1 {
		var jsum float64
		for i := 1; i < got; i++ {
			d := rtts[i] - rtts[i-1]
			if d < 0 {
				d = -d
			}
			jsum += d
		}
		jitterMs = jsum / float64(got-1)
	}

	return true, rttMs, lossPct, jitterMs
}
