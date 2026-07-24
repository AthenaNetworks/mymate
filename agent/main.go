// Command mymate-agent is the My Mate remote agent (probe). It runs inside a management
// network, dials an outbound encrypted WebSocket to the central app, and polls/discovers
// devices locally so the management network is never exposed to the internet.
//
// A single static binary - no runtime dependencies - so it runs the same on a VM, a
// container, a Raspberry Pi, or Windows. It can run in the foreground, or install itself as a
// service:
//
//	mymate-agent                                  run in the foreground (or under systemd/SCM)
//	mymate-agent install --url ... --token ...    register + start a Windows/systemd service
//	mymate-agent uninstall                         stop + remove the service
//	mymate-agent start | stop                      control an installed service
//	mymate-agent version
//
// Configuration is via environment variables (MYMATE_URL, MYMATE_AGENT_TOKEN, ...), with a
// config-file fallback the installer writes (see internal/config). Recovery is handled both
// in-process (reconnect with backoff) and by the service manager (Restart / OnFailure).
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"strings"

	"github.com/kardianos/service"

	"github.com/AthenaNetworks/mymate/agent/internal/agent"
	"github.com/AthenaNetworks/mymate/agent/internal/config"
)

func main() {
	args := os.Args[1:]
	cmd := ""
	if len(args) > 0 && !strings.HasPrefix(args[0], "-") {
		cmd = args[0]
		args = args[1:]
	}

	switch cmd {
	case "version":
		fmt.Printf("mymate-agent %s (%s)\n", agent.Version, agent.Platform())
	case "install", "uninstall", "start", "stop", "status":
		if err := control(cmd, args); err != nil {
			fmt.Fprintln(os.Stderr, cmd+":", err)
			os.Exit(1)
		}
	case "", "run":
		if err := run(args); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q (try: run, install, uninstall, start, stop, version)\n", cmd)
		os.Exit(2)
	}
}

// run loads config and runs the agent - in the foreground when interactive, or wired to the
// service manager (Windows SCM / systemd / launchd) when started as a service.
func run(args []string) error {
	fs := flag.NewFlagSet("run", flag.ContinueOnError)
	showVersion := fs.Bool("version", false, "print the agent version and exit")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *showVersion {
		fmt.Printf("mymate-agent %s (%s)\n", agent.Version, agent.Platform())
		return nil
	}

	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("config error: %w", err)
	}

	logger := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel}))
	slog.SetDefault(logger)
	slog.Info("mymate-agent starting", "version", agent.Version, "platform", agent.Platform(), "server", cfg.RedactedURL())

	svc, err := service.New(&program{cfg: cfg}, serviceConfig())
	if err != nil {
		return err
	}
	// Run blocks: foreground (Start -> wait for signal -> Stop) off a service manager, or wired
	// to the Windows SCM / systemd when launched as a service. Either way agent.Run does the work.
	if err := svc.Run(); err != nil && !errors.Is(err, context.Canceled) {
		return err
	}
	slog.Info("mymate-agent stopped")
	return nil
}

// control installs/removes/starts/stops the service. install accepts the server URL + token and
// writes them to the config file the service reads on startup.
func control(action string, args []string) error {
	if action == "install" {
		fs := flag.NewFlagSet("install", flag.ContinueOnError)
		url := fs.String("url", "", "central app URL, e.g. https://app.example.com")
		token := fs.String("token", "", "agent token from `mymate:agent:create`")
		name := fs.String("name", "", "optional label reported to the server (defaults to hostname)")
		if err := fs.Parse(args); err != nil {
			return err
		}
		if strings.TrimSpace(*url) == "" || strings.TrimSpace(*token) == "" {
			return fmt.Errorf("--url and --token are required")
		}
		if err := config.WriteFile(*url, *token, *name); err != nil {
			return fmt.Errorf("write config (%s): %w", config.FilePath(), err)
		}
	}

	svc, err := service.New(&program{}, serviceConfig())
	if err != nil {
		return err
	}

	switch action {
	case "install":
		if err := svc.Install(); err != nil {
			return err
		}
		fmt.Printf("installed. config at %s\n", config.FilePath())
		if err := svc.Start(); err != nil {
			return fmt.Errorf("installed, but starting failed: %w", err)
		}
		fmt.Println("started.")
		return nil
	case "uninstall":
		_ = svc.Stop()
		return svc.Uninstall()
	case "start":
		return svc.Start()
	case "stop":
		return svc.Stop()
	case "status":
		st, err := svc.Status()
		if err != nil {
			return err
		}
		fmt.Println([]string{"unknown", "running", "stopped"}[st])
		return nil
	}
	return nil
}
