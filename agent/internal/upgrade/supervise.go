package upgrade

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"time"
)

// ErrRestart is returned by an agent run loop that has staged a new version and wants to be
// restarted into it.
var ErrRestart = errors.New("restarting into a staged upgrade")

// Exit codes a staged child uses to talk to its supervisor.
const (
	ExitRestart   = 10 // staged another version - start the newest
	ExitUnhealthy = 11 // never reached a stable hub connection within HealthDeadline
)

// restartBackoff spaces out restarts of a crashing child, so a crash loop can't spin.
var restartBackoff = 5 * time.Second

// Supervise runs the agent until ctx is cancelled. When a staged version is usable it runs it as
// a child process and watches it; otherwise it runs the base agent in-process via runSelf. A
// staged version that keeps failing is marked bad and the next best version (in the end, the
// base) takes over - so the agent always comes back online.
func Supervise(ctx context.Context, m *Manager, runSelf func(context.Context) error) error {
	for {
		if err := ctx.Err(); err != nil {
			return err
		}
		v := m.Choose()
		if v == "" {
			err := runSelf(ctx)
			if errors.Is(err, ErrRestart) {
				continue
			}
			return err
		}

		attempt := m.BeginAttempt(v)
		slog.Info("starting staged agent version", "version", v, "attempt", attempt, "of", MaxAttempts)
		code, err := runChild(ctx, m.BinaryPath(v), v)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if code == ExitRestart {
			continue // it staged something newer
		}

		// It stopped on its own: a crash, or it never got a stable connection. Attempts are reset
		// whenever a version becomes healthy, so this only adds up for one that keeps failing.
		why := describeExit(code, err)
		if m.Load().Attempts >= MaxAttempts {
			slog.Error("staged agent version keeps failing, rolling back", "version", v, "last_exit", why)
			m.MarkBad(v, fmt.Sprintf("didn't stay connected to the hub after %d starts (last: %s)", MaxAttempts, why))
			continue
		}
		slog.Warn("staged agent version stopped, restarting it", "version", v, "exit", why)
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(restartBackoff):
		}
	}
}

// childCommand builds the command for a staged child. A package variable so tests can swap it.
var childCommand = func(ctx context.Context, bin, version string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, bin, "run")
	cmd.Env = append(os.Environ(), EnvStaged+"="+version)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	return cmd
}

// runChild runs bin and waits. Cancelling ctx asks the child to stop (SIGTERM, or a kill on
// Windows) and waits briefly for it to unwind.
func runChild(ctx context.Context, bin, version string) (int, error) {
	cmd := childCommand(ctx, bin, version)
	cmd.Cancel = func() error { return stopProcess(cmd.Process) }
	cmd.WaitDelay = 15 * time.Second
	err := cmd.Run()
	if cmd.ProcessState != nil {
		return cmd.ProcessState.ExitCode(), err
	}
	return -1, err
}

func describeExit(code int, err error) string {
	switch {
	case code == ExitUnhealthy:
		return fmt.Sprintf("no stable hub connection within %s", HealthDeadline)
	case err != nil && code < 0:
		return err.Error()
	default:
		return fmt.Sprintf("exit code %d", code)
	}
}
