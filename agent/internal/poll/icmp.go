package poll

import (
	"net"
	"os"
	"time"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

// pingOnce sends one ICMP echo to host and returns the round-trip time (ms) and whether an echo
// reply came back within timeout. It opens its own socket per call so concurrent pings don't cross
// replies.
//
// It prefers an unprivileged "ping" datagram socket (udp4 - works when
// net.ipv4.ping_group_range permits the user), falling back to a raw socket (needs
// CAP_NET_RAW, which the shipped systemd unit grants). Same privilege model as fping.
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
		return 0, false
	}
	defer conn.Close()

	msg := icmp.Message{
		Type: ipv4.ICMPTypeEcho,
		Body: &icmp.Echo{ID: os.Getpid() & 0xffff, Seq: 1, Data: []byte("mymate-agent")},
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
		n, _, err := conn.ReadFrom(rb)
		if err != nil {
			return 0, false // timeout / error -> unreachable
		}
		rm, err := icmp.ParseMessage(1, rb[:n]) // 1 = ICMPv4 protocol number
		if err != nil {
			continue
		}
		if rm.Type == ipv4.ICMPTypeEchoReply {
			return float64(time.Since(start).Microseconds()) / 1000.0, true
		}
	}
	return 0, false
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
