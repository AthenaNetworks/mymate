package poll

import (
	"net"
	"os"
	"time"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

// ping sends one ICMP echo to host and reports whether an echo reply came back within
// timeout. It opens its own socket per call so concurrent pings don't cross replies.
//
// It prefers an unprivileged "ping" datagram socket (udp4 - works when
// net.ipv4.ping_group_range permits the user), falling back to a raw socket (needs
// CAP_NET_RAW, which the shipped systemd unit grants). Same privilege model as fping.
func ping(host string, timeout time.Duration) bool {
	dst, err := net.ResolveIPAddr("ip4", host)
	if err != nil {
		return false
	}

	conn, err := icmp.ListenPacket("udp4", "0.0.0.0")
	unprivileged := true
	if err != nil {
		conn, err = icmp.ListenPacket("ip4:icmp", "0.0.0.0")
		unprivileged = false
	}
	if err != nil {
		return false
	}
	defer conn.Close()

	msg := icmp.Message{
		Type: ipv4.ICMPTypeEcho,
		Body: &icmp.Echo{ID: os.Getpid() & 0xffff, Seq: 1, Data: []byte("mymate-agent")},
	}
	wb, err := msg.Marshal(nil)
	if err != nil {
		return false
	}

	var addr net.Addr = dst
	if unprivileged {
		addr = &net.UDPAddr{IP: dst.IP} // ping sockets address by UDP
	}

	deadline := time.Now().Add(timeout)
	_ = conn.SetDeadline(deadline)
	if _, err := conn.WriteTo(wb, addr); err != nil {
		return false
	}

	rb := make([]byte, 1500)
	for time.Now().Before(deadline) {
		n, _, err := conn.ReadFrom(rb)
		if err != nil {
			return false // timeout / error -> unreachable
		}
		rm, err := icmp.ParseMessage(1, rb[:n]) // 1 = ICMPv4 protocol number
		if err != nil {
			continue
		}
		if rm.Type == ipv4.ICMPTypeEchoReply {
			return true
		}
	}
	return false
}
