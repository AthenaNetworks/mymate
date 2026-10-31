package poll

import (
	"testing"
	"time"
)

func TestStateRate(t *testing.T) {
	s := newState()
	t0 := time.Now()

	// First sample: no rate yet.
	if in, out := s.rate(1, 1000, 2000, false, t0); in != nil || out != nil {
		t.Fatal("first sample should yield no rate")
	}

	// 1s later, +125000 in octets -> 125000*8 = 1,000,000 bps.
	in, out := s.rate(1, 1000+125000, 2000+250000, false, t0.Add(time.Second))
	if in == nil || *in != 1_000_000 {
		t.Errorf("in bps = %v, want 1000000", in)
	}
	if out == nil || *out != 2_000_000 {
		t.Errorf("out bps = %v, want 2000000", out)
	}

	// Counter reset (went backwards) -> discard, no rate.
	if in, _ := s.rate(1, 5, 5, false, t0.Add(2*time.Second)); in != nil {
		t.Error("counter reset should yield no rate")
	}
}

// GitHub #55: a device that only refreshes its counters every ~25s, polled every 12s.
func TestStateRateRidesOutUnrefreshedCounters(t *testing.T) {
	s := newState()
	t0 := time.Unix(1_700_000_000, 0)
	s.rate(1, 0, 0, false, t0)
	in, _ := s.rate(1, 90_000_000, 0, false, t0.Add(12*time.Second)) // 60 Mbps
	if in == nil || *in != 60_000_000 {
		t.Fatalf("first rate = %v", in)
	}

	// Counters frozen 12s later: repeat the last rate, don't read 0.
	in, _ = s.rate(1, 90_000_000, 0, false, t0.Add(24*time.Second))
	if in == nil || *in != 60_000_000 {
		t.Fatalf("unrefreshed read = %v, want the carried 60 Mbps", in)
	}

	// The next refresh covers both intervals: 180 MB over the 24s since the baseline, not 12s.
	in, _ = s.rate(1, 270_000_000, 0, false, t0.Add(36*time.Second))
	if in == nil || *in != 60_000_000 {
		t.Fatalf("after refresh = %v, want 60 Mbps not a 2x spike", in)
	}

	// Genuinely idle past the window: 0.
	in, _ = s.rate(1, 270_000_000, 0, false, t0.Add(36*time.Second+staleWindow))
	if in == nil || *in != 0 {
		t.Fatalf("idle port = %v, want 0", in)
	}
}
