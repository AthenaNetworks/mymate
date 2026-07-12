package poll

import "testing"

func TestExpandCIDR(t *testing.T) {
	cases := []struct {
		cidr      string
		wantFirst string
		wantLast  string
		wantCount int
		wantEmpty bool
	}{
		// /24 -> 254 usable hosts (network + broadcast excluded).
		{cidr: "192.168.1.0/24", wantFirst: "192.168.1.1", wantLast: "192.168.1.254", wantCount: 254},
		// /30 -> 2 usable hosts.
		{cidr: "10.0.0.0/30", wantFirst: "10.0.0.1", wantLast: "10.0.0.2", wantCount: 2},
		// /31 -> both addresses usable (point-to-point).
		{cidr: "10.0.0.0/31", wantFirst: "10.0.0.0", wantLast: "10.0.0.1", wantCount: 2},
		// /32 -> the single host.
		{cidr: "10.0.0.5/32", wantFirst: "10.0.0.5", wantLast: "10.0.0.5", wantCount: 1},
		// Garbage / IPv6 -> nothing.
		{cidr: "not-a-cidr", wantEmpty: true},
		{cidr: "2001:db8::/64", wantEmpty: true},
	}

	for _, c := range cases {
		got := expandCIDR(c.cidr, maxScanHosts)
		if c.wantEmpty {
			if len(got) != 0 {
				t.Errorf("%s: want empty, got %d hosts", c.cidr, len(got))
			}
			continue
		}
		if len(got) != c.wantCount {
			t.Errorf("%s: count = %d, want %d", c.cidr, len(got), c.wantCount)
			continue
		}
		if got[0] != c.wantFirst {
			t.Errorf("%s: first = %s, want %s", c.cidr, got[0], c.wantFirst)
		}
		if got[len(got)-1] != c.wantLast {
			t.Errorf("%s: last = %s, want %s", c.cidr, got[len(got)-1], c.wantLast)
		}
	}
}

func TestExpandCIDRCap(t *testing.T) {
	// A /16 has 65,534 usable hosts but must be capped.
	got := expandCIDR("10.0.0.0/16", 100)
	if len(got) != 100 {
		t.Errorf("cap not honoured: got %d, want 100", len(got))
	}
	if got[0] != "10.0.0.1" {
		t.Errorf("first = %s, want 10.0.0.1", got[0])
	}
}
