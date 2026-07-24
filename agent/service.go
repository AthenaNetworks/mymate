package main

import (
	"context"
	"log/slog"

	"github.com/kardianos/service"

	"github.com/AthenaNetworks/mymate/agent/internal/agent"
	"github.com/AthenaNetworks/mymate/agent/internal/config"
)

// program adapts the agent run loop to kardianos/service, so the same binary runs as a Windows
// service, a systemd/launchd service, or in the foreground - with no change to agent.Run.
type program struct {
	cfg    *config.Config
	cancel context.CancelFunc
	done   chan struct{}
}

// Start is non-blocking (a service requirement): it kicks the run loop off in a goroutine.
func (p *program) Start(service.Service) error {
	p.done = make(chan struct{})
	ctx, cancel := context.WithCancel(context.Background())
	p.cancel = cancel
	go func() {
		defer close(p.done)
		if err := agent.Run(ctx, p.cfg); err != nil && ctx.Err() == nil {
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
