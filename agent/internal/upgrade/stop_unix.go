//go:build !windows

package upgrade

import (
	"os"
	"syscall"
)

// stopProcess asks a child to shut down cleanly.
func stopProcess(p *os.Process) error { return p.Signal(syscall.SIGTERM) }
