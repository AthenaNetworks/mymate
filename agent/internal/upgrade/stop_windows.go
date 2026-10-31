//go:build windows

package upgrade

import "os"

// stopProcess stops a child. Windows has no SIGTERM for a console-less child, so it's a kill;
// the agent holds nothing that needs flushing.
func stopProcess(p *os.Process) error { return p.Kill() }
