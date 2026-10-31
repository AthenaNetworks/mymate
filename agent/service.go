package main

import (
	"context"
	"log/slog"

	"github.com/kardianos/service"

	"github.com/AthenaNetworks/mymate/agent/internal/agent"
	"github.com/AthenaNetworks/mymate/agent/internal/config"
	"github.com/AthenaNetworks/mymate/agent/internal/upgrade"
)

// program adapts the agent run loop to kardianos/service, so the same binary runs as a Windows
// service, a systemd/launchd service, or in the foreground - with no change to agent.Run.
type program struct {
	cfg    *config.Config
	cancel context.CancelFunc
	done   chan struct{}
}

// runAgent is the service's work. Where the agent can upgrade itself it runs under the upgrade
// supervisor, which starts the newest healthy staged version and falls back if one fails;
// otherwise it's the plain agent loop.
func (p *program) runAgent(ctx context.Context) error {
	mgr := upgrade.New(agent.Version)
	if mgr == nil {
		return agent.Run(ctx, p.cfg)
	}
	return upgrade.Supervise(ctx, mgr, func(ctx context.Context) error {
		return agent.RunWith(ctx, p.cfg, &agent.Options{Upgrader: mgr})
	})
}

// Start is non-blocking (a service requirement): it kicks the run loop off in a goroutine.
func (p *program) Start(service.Service) error {
	p.done = make(chan struct{})
	ctx, cancel := context.WithCancel(context.Background())
	p.cancel = cancel
	go func() {
		defer close(p.done)
		if err := p.runAgent(ctx); err != nil && ctx.Err() == nil {
			slog.Error("agent exited with error", "error", err)
		}
	}()
	return nil
}

// Stop cancels the run loop and waits for it to unwind so the service manager gets a clean exit.
func (p *program) Stop(service.Service) error {
	if p.cancel != nil {
		p.cancel()
	}
	if p.done != nil {
		<-p.done
	}
	return nil
}

func serviceConfig() *service.Config {
	return &service.Config{
		Name:        "mymate-agent",
		DisplayName: "My Mate Agent",
		Description: "My Mate remote monitoring agent - dials out to the central app and polls devices locally.",
		Option: service.KeyValue{
			// Restart the service if it exits unexpectedly (parity with systemd Restart=always).
			"OnFailure":              "restart",
			"OnFailureDelayDuration": "5s",
			"OnFailureResetPeriod":   10,
		},
	}
}
