package poll

import (
	"net"
	"testing"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

func echoReply(id, seq int) *icmp.Message {
	return &icmp.Message{Type: ipv4.ICMPTypeEchoReply, Body: &icmp.Echo{ID: id, Seq: seq}}
}

// A raw socket sees every echo reply on the box. Only the one from our target, with our id and
// seq, should count, otherwise one live host makes every concurrent ping look up.
func TestIsOurEchoReplyRawSocket(t *testing.T) {
	dst := net.ParseIP("10.0.0.5")
	raw := func(ip string) net.Addr { return &net.IPAddr{IP: net.ParseIP(ip)} }

	if !isOurEchoReply(echoReply(42, 7), raw("10.0.0.5"), dst, 42, 7, true) {
		t.Error("our own reply was rejected")
	}
	if isOurEchoReply(echoReply(42, 7), raw("10.0.0.6"), dst, 42, 7, true) {
		t.Error("reply from another host was accepted")
	}
	if isOurEchoReply(echoReply(42, 8), raw("10.0.0.5"), dst, 42, 7, true) {
		t.Error("reply to a different seq was accepted")
	}
	if isOurEchoReply(echoReply(99, 7), raw("10.0.0.5"), dst, 42, 7, true) {
		t.Error("reply for another process id was accepted")
	}
	req := &icmp.Message{Type: ipv4.ICMPTypeEcho, Body: &icmp.Echo{ID: 42, Seq: 7}}
	if isOurEchoReply(req, raw("10.0.0.5"), dst, 42, 7, true) {
		t.Error("an echo request (our own outbound on loopback) was accepted as a reply")
	}
}

// On a ping socket the kernel swaps the id for its own, so the id must not be checked there.
func TestIsOurEchoReplyPingSocketIgnoresID(t *testing.T) {
	dst := net.ParseIP("10.0.0.5")
	peer := &net.UDPAddr{IP: net.ParseIP("10.0.0.5")}
	if !isOurEchoReply(echoReply(31337, 7), peer, dst, 42, 7, false) {
		t.Error("ping socket reply rejected because of the kernel rewritten id")
	}
	if isOurEchoReply(echoReply(31337, 7), &net.UDPAddr{IP: net.ParseIP("10.0.0.9")}, dst, 42, 7, false) {
		t.Error("ping socket reply from the wrong host accepted")
	}
}

// A per-device ping source (#11) binds the ICMP socket to that address; no source keeps the old
// wildcard bind. Anything that isn't IPv4 is refused (the agent pings over ip4 only).
func TestBindAddr(t *testing.T) {
	cases := []struct {
		in   string
		want string
		ok   bool
	}{
		{"", "0.0.0.0", true},
		{"  ", "0.0.0.0", true},
		{"192.0.2.10", "192.0.2.10", true},
		{" 192.0.2.10 ", "192.0.2.10", true},
		{"2001:db8::1", "", false},
		{"not-an-ip", "", false},
	}
	for _, c := range cases {
		got, ok := bindAddr(c.in)
		if got != c.want || ok != c.ok {
			t.Errorf("bindAddr(%q) = %q, %v; want %q, %v", c.in, got, ok, c.want, c.ok)
		}
	}
}

// A source that can't be bound means the ping fails (reported down), never a silent fallback
// to the default route.
func TestPingOnceWithUnusableSourceFails(t *testing.T) {
	if _, ok := pingOnce("127.0.0.1", "2001:db8::1", pingTimeout); ok {
		t.Fatal("expected an IPv6 source to fail the ip4 ping")
	}
}
