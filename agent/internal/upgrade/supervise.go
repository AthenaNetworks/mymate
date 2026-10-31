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
		code, err := runChild(ctx, m, m.BinaryPath(v), v)
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

// supervisorGrace is how long past HealthDeadline the supervisor waits for an unhealthy child to
// give up by itself before stopping it.
var supervisorGrace = 30 * time.Second

// healthPoll is how often the supervisor checks whether its child has become healthy.
var healthPoll = 5 * time.Second

// killDelay: a child asked to stop that's still running this much later is killed.
var killDelay = 15 * time.Second

// runChild runs bin and waits. Cancelling ctx asks the child to stop (SIGTERM, or a kill on
// Windows) and waits briefly for it to unwind.
//
// It also enforces the health deadline itself. The child is meant to give up on its own when it
// can't get a stable connection, but a version that hangs - deadlocked, stuck in a syscall, a
// broken build - never would, and the agent would be offline for good. So if the child hasn't
// marked itself healthy (MarkHealthy resets the attempt count) by HealthDeadline plus a grace
// period, it's stopped and counted as a failed start.
func runChild(ctx context.Context, m *Manager, bin, version string) (int, error) {
	runCtx, cancel := context.WithCancelCause(ctx)
	defer cancel(nil)
	cmd := childCommand(runCtx, bin, version)
	cmd.Cancel = func() error { return stopProcess(cmd.Process) }
	cmd.WaitDelay = killDelay // then it's killed outright

	if err := cmd.Start(); err != nil {
		return -1, err
	}
	deadline, poll := time.Now().Add(HealthDeadline+supervisorGrace), healthPoll
	go func() {
		t := time.NewTicker(poll)
		defer t.Stop()
		for {
			select {
			case <-runCtx.Done():
				return
			case <-t.C:
				if m.Load().Attempts == 0 {
					return // healthy: from here it's just a running agent
				}
				if time.Now().After(deadline) {
					slog.Error("staged agent version isn't healthy and didn't give up by itself, stopping it", "version", version)
					cancel(errHung)
					return
				}
			}
		}
	}()
	err := cmd.Wait()
	if errors.Is(context.Cause(runCtx), errHung) && ctx.Err() == nil {
		return ExitUnhealthy, errHung
	}
	if cmd.ProcessState != nil {
		return cmd.ProcessState.ExitCode(), err
	}
	return -1, err
}

var errHung = errors.New("stopped by the supervisor: never became healthy")

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
