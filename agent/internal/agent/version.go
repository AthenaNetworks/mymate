package agent

import "runtime"

// Version is set at build time via -ldflags "-X .../agent.Version=v1.2.3".
var Version = "dev"

// Platform reports the OS/arch the binary was built for, e.g. "linux/arm64".
func Platform() string {
	return runtime.GOOS + "/" + runtime.GOARCH
}
