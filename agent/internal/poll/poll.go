// Package poll executes poll jobs locally on the agent's network - ICMP up/down and SNMP
// throughput - and returns results for the hub to fold into the central pipeline.
package poll

import (
	"context"
	"sync"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
)

const (
	pingTimeout = 1 * time.Second
	pingWorkers = 32 // bound concurrent pings so a big site doesn't open thousands of sockets
)

// Poller holds the per-interface counter state so bps deltas survive across polls (and
// reconnects). Safe for concurrent use.
type Poller struct {
	state *state
}

func New() *Poller { return &Poller{state: newState()} }

// Run executes a poll job and returns the results (up/down + throughput + cpu/mem/temp).
func (p *Poller) Run(ctx context.Context, job proto.PollJob) proto.ResultPayload {
	flows := p.runSNMP(job.SNMP)
	var metrics []proto.MetricsResult
	var discovery []proto.DeviceDiscovery
	for _, t := range job.SNMP {
		if m := p.pollSNMPMetrics(t); m != nil {
			metrics = append(metrics, *m)
		}
		// Discovery cadence: (re)walk interfaces + facts so an agent-polled device is
		// discovered from the agent, not the central server (#33).
		if t.Discover {
			if d := p.discoverDevice(t); d != nil {
				discovery = append(discovery, *d)
			}
		}
	}
	for _, t := range job.RouterOS {
		flows = append(flows, p.pollRouterOS(t)...)
		if m := p.pollRouterOSMetrics(t); m != nil {
			metrics = append(metrics, *m)
		}
	}
	return proto.ResultPayload{
		Pings:      p.runPings(ctx, job.Ping),
		Throughput: flows,
		Metrics:    metrics,
		Discovery:  discovery,
	}
}

func (p *Poller) runPings(ctx context.Context, targets []proto.PingTarget) []proto.PingResult {
	if len(targets) == 0 {
		return nil
	}
	results := make([]proto.PingResult, len(targets))
	sem := make(chan struct{}, pingWorkers)
	var wg sync.WaitGroup

	for i, t := range targets {
		if ctx.Err() != nil {
			break
		}
		wg.Add(1)
		sem <- struct{}{}
		go func(i int, t proto.PingTarget) {
			defer wg.Done()
			defer func() { <-sem }()
			results[i] = proto.PingResult{DeviceID: t.DeviceID, Up: ping(t.IP, pingTimeout)}
		}(i, t)
	}
	wg.Wait()
	return results
}

func (p *Poller) runSNMP(targets []proto.SNMPTarget) []proto.FlowResult {
	var flows []proto.FlowResult
	for _, t := range targets {
		flows = append(flows, p.pollSNMP(t)...)
	}
	return flows
}
