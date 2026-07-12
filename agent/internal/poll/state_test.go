package poll

import (
	"testing"
	"time"
)

func TestStateRate(t *testing.T) {
	s := newState()
	t0 := time.Now()

	// First sample: no rate yet.
	if in, out := s.rate(1, 1000, 2000, t0); in != nil || out != nil {
		t.Fatal("first sample should yield no rate")
	}

	// 1s later, +125000 in octets -> 125000*8 = 1,000,000 bps.
	in, out := s.rate(1, 1000+125000, 2000+250000, t0.Add(time.Second))
	if in == nil || *in != 1_000_000 {
		t.Errorf("in bps = %v, want 1000000", in)
	}
	if out == nil || *out != 2_000_000 {
		t.Errorf("out bps = %v, want 2000000", out)
	}

	// Counter reset (went backwards) -> discard, no rate.
	if in, _ := s.rate(1, 5, 5, t0.Add(2*time.Second)); in != nil {
		t.Error("counter reset should yield no rate")
	}
}
