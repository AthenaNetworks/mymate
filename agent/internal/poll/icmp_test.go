package poll

import "testing"

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
