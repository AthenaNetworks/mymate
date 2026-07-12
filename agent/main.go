// Command mymate-agent is the My Mate remote agent (probe). It runs inside a management
// network, dials an outbound encrypted WebSocket to the central app, and polls/discovers
// devices locally so the management network is never exposed to the internet.
//
// A single static binary - no runtime dependencies - so it runs the same on a VM, a
// container, or a Raspberry Pi. Configuration is via environment variables (see the
// packaging/ service files); recovery is handled both in-process (reconnect with backoff)
// and by the service manager (systemd/launchd Restart=always).
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/AthenaNetworks/mymate/agent/internal/agent"
	"github.com/AthenaNetworks/mymate/agent/internal/config"
)

func main() {
	showVersion := flag.Bool("version", false, "print the agent version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Printf("mymate-agent %s (%s)\n", agent.Version, agent.Platform())
		return
	}

	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintln(os.Stderr, "config error:", err)
		os.Exit(2)
	}

	logger := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel}))
	slog.SetDefault(logger)
	slog.Info("mymate-agent starting", "version", agent.Version, "platform", agent.Platform(), "server", cfg.RedactedURL())

	// Cancel on SIGINT/SIGTERM so the service manager gets a clean shutdown.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	if err := agent.Run(ctx, cfg); err != nil && !errors.Is(err, context.Canceled) {
		slog.Error("agent exited with error", "error", err)
		os.Exit(1)
	}
	slog.Info("mymate-agent stopped")
}
