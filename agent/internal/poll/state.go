package poll

import (
	"sync"
	"time"
)

// counterState remembers an interface's last SNMP octet counters so the next poll can turn
// them into a bits/sec rate - the agent computes bps itself (it holds consecutive samples),
// with the same counter-reset guard the central SNMP path uses.
type counterState struct {
	in, out uint64
	ts      time.Time
}

type state struct {
	mu sync.Mutex
	m  map[int]counterState
}

func newState() *state { return &state{m: map[int]counterState{}} }

// rate records the new counters and returns (inBps, outBps) vs the previous sample, or
// (nil, nil) when there's no rate yet: the first sample for this interface, no elapsed time,
// or a counter that went backwards (reset/wrap - discard rather than emit a garbage spike).
func (s *state) rate(ifID int, in, out uint64, now time.Time) (inBps, outBps *float64) {
	s.mu.Lock()
	defer s.mu.Unlock()

	prev, ok := s.m[ifID]
	s.m[ifID] = counterState{in: in, out: out, ts: now}
	if !ok {
		return nil, nil
	}
	dt := now.Sub(prev.ts).Seconds()
	if dt <= 0 || in < prev.in || out < prev.out {
		return nil, nil
	}
	i := float64(in-prev.in) * 8 / dt
	o := float64(out-prev.out) * 8 / dt
	return &i, &o
}
