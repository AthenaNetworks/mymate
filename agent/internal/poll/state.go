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
	is32    bool // the 32-bit ifTable octets (v1 / no HC counters), which wrap
	// the rate last returned, carried while the device hasn't refreshed its counters
	inBps, outBps *float64
}

// staleWindow: both counters unchanged since a read less than this long ago means the device
// hasn't refreshed them yet (Cisco IOS-XE only does every ~20-30s, GitHub #55), not an idle port.
// Same as the server's poll.counter_stale_window default.
const staleWindow = 60 * time.Second

// portState is the same idea for the port counters (packets / errors / discards), kept apart
// because they're read on their own, slower, cadence over SNMP.
type portState struct {
	c  map[string]uint64
	ts time.Time
}

type state struct {
	mu    sync.Mutex
	m     map[int]counterState
	ports map[int]portState
}

func newState() *state { return &state{m: map[int]counterState{}, ports: map[int]portState{}} }

// rate records the new counters and returns (inBps, outBps) vs the previous sample, or
// (nil, nil) when there's no rate yet: the first sample for this interface, no elapsed time,
// or a counter that went backwards (reset/wrap - discard rather than emit a garbage spike).
//
// is32 is the ifInOctets/ifOutOctets fallback: those do wrap, so a backwards step goes through
// the same Counter32 wrap check as the port counters. A port that switched between the 64 and
// 32-bit counters since the last read starts over rather than diffing the two.
func (s *state) rate(ifID int, in, out uint64, is32 bool, now time.Time) (inBps, outBps *float64) {
	s.mu.Lock()
	defer s.mu.Unlock()

	prev, ok := s.m[ifID]
	if ok && prev.is32 == is32 && in == prev.in && out == prev.out {
		if age := now.Sub(prev.ts); age > 0 && age < staleWindow {
			// Not refreshed yet: keep the old baseline so the next change is spread over the
			// whole time since it, and repeat the last rate instead of a 0 then a 2x spike.
			return prev.inBps, prev.outBps
		}
	}
	cur := counterState{in: in, out: out, ts: now, is32: is32}
	defer func() { s.m[ifID] = cur }()
	if !ok || prev.is32 != is32 {
		return nil, nil
	}
	dt := now.Sub(prev.ts).Seconds()
	if dt <= 0 {
		return nil, nil
	}
	di, okIn := counterDelta(prev.in, in, is32)
	do, okOut := counterDelta(prev.out, out, is32)
	if !okIn || !okOut {
		return nil, nil
	}
	i := float64(di) * 8 / dt
	o := float64(do) * 8 / dt
	cur.inBps, cur.outBps = &i, &o
	return &i, &o
}

// portRates records a new set of port counters for an interface and returns the per-second
// rate of each one vs the previous read (nil where there's no rate yet). Same rules as the
// server's RateCalculator::counterRate: a 64-bit counter going backwards is a reset, a Counter32
// one is taken as a wrap only when the wrapped delta is under half the counter space.
func (s *state) portRates(ifID int, c map[string]uint64, counter32 map[string]bool, now time.Time) map[string]*float64 {
	s.mu.Lock()
	defer s.mu.Unlock()

	prev, ok := s.ports[ifID]
	s.ports[ifID] = portState{c: c, ts: now}

	out := make(map[string]*float64, len(c))
	if !ok {
		return out
	}
	dt := now.Sub(prev.ts).Seconds()
	if dt <= 0 {
		return out
	}
	for name, cur := range c {
		last, had := prev.c[name]
		if !had {
			continue
		}
		if d, ok := counterDelta(last, cur, counter32[name]); ok {
			r := float64(d) / dt
			out[name] = &r
		}
	}
	return out
}

const counter32Max = uint64(4294967295)

// counterDelta is how far a counter moved, and whether that can be trusted.
func counterDelta(last, cur uint64, is32 bool) (uint64, bool) {
	if cur >= last {
		return cur - last, true
	}
	if !is32 || last > counter32Max || cur > counter32Max {
		return 0, false // reset
	}
	d := counter32Max - last + cur + 1
	if d > counter32Max/2 {
		return 0, false // a reboot back to near zero, not a wrap
	}
	return d, true
}
