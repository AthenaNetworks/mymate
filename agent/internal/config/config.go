// Package config loads the agent's configuration from the environment. systemd/launchd
// provide these via an EnvironmentFile / plist, so there's one config surface everywhere.
package config

import (
	"bufio"
	"fmt"
	"log/slog"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

type Config struct {
	// ServerURL is the WebSocket URL the agent dials, e.g. wss://app.example.com/agent.
	// Derived from MYMATE_URL (http(s):// is rewritten to ws(s):// and /agent appended).
	ServerURL string
	// Token authenticates the agent (sent as a Bearer header on the WS handshake).
	Token string
	// Name is an optional label reported to the server (defaults to the hostname).
	Name string
	// InsecureTLS skips TLS verification (self-signed dev servers only).
	InsecureTLS bool
	LogLevel    slog.Level
}

// FilePath is where the agent reads its config file when the env isn't already set. On Linux
// systemd/launchd inject the env directly, so this mainly serves Windows (where a service has no
// easy EnvironmentFile), but it works everywhere. Override with MYMATE_AGENT_CONFIG.
func FilePath() string {
	if p := strings.TrimSpace(os.Getenv("MYMATE_AGENT_CONFIG")); p != "" {
		return p
	}
	if runtime.GOOS == "windows" {
		base := os.Getenv("ProgramData")
		if base == "" {
			base = `C:\ProgramData`
		}
		return filepath.Join(base, "mymate-agent", "agent.env")
	}
	return "/etc/mymate-agent/agent.env"
}

// loadFile fills any MYMATE_* env vars that aren't already set from a KEY=VALUE file (the same
// format systemd's EnvironmentFile uses), so the config surface is identical everywhere.
func loadFile() {
	f, err := os.Open(FilePath())
	if err != nil {
		return // no file - env only
	}
	defer f.Close()

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		v = strings.Trim(strings.TrimSpace(v), `"'`)
		if os.Getenv(k) == "" {
			_ = os.Setenv(k, v)
		}
	}
}

// WriteFile persists the URL/token/name to the config file (used by `install` on Windows, where
// a service can't easily carry an EnvironmentFile). Written owner-only where the OS honours it.
func WriteFile(serverURL, token, name string) error {
	path := FilePath()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	var b strings.Builder
	b.WriteString("# My Mate agent config - written by `mymate-agent install`.\n")
	fmt.Fprintf(&b, "MYMATE_URL=%s\n", serverURL)
	fmt.Fprintf(&b, "MYMATE_AGENT_TOKEN=%s\n", token)
	if strings.TrimSpace(name) != "" {
		fmt.Fprintf(&b, "MYMATE_AGENT_NAME=%s\n", name)
	}
	return os.WriteFile(path, []byte(b.String()), 0o600)
}

// Load reads and validates configuration from the environment (with a config-file fallback).
func Load() (*Config, error) {
	loadFile()
	raw := strings.TrimSpace(os.Getenv("MYMATE_URL"))
	token := strings.TrimSpace(os.Getenv("MYMATE_AGENT_TOKEN"))
	if raw == "" {
		return nil, fmt.Errorf("MYMATE_URL is required (e.g. https://app.example.com)")
	}
	if token == "" {
		return nil, fmt.Errorf("MYMATE_AGENT_TOKEN is required (from `mymate:agent:create`)")
	}

	wsURL, err := toWebSocketURL(raw)
	if err != nil {
		return nil, err
	}

	name := strings.TrimSpace(os.Getenv("MYMATE_AGENT_NAME"))
	if name == "" {
		name, _ = os.Hostname()
	}

	level := slog.LevelInfo
	if strings.EqualFold(os.Getenv("MYMATE_AGENT_LOG"), "debug") {
		level = slog.LevelDebug
	}

	return &Config{
		ServerURL:   wsURL,
		Token:       token,
		Name:        name,
		InsecureTLS: isTruthy(os.Getenv("MYMATE_AGENT_INSECURE")),
		LogLevel:    level,
	}, nil
}

// toWebSocketURL turns a user-facing app URL into the agent WebSocket endpoint.
// http->ws, https->wss; ws(s) is accepted as-is. Appends /agent if no path is given.
func toWebSocketURL(raw string) (string, error) {
	u, err := url.Parse(raw)
	if err != nil {
		return "", fmt.Errorf("invalid MYMATE_URL %q: %w", raw, err)
	}
	switch u.Scheme {
	case "http":
		u.Scheme = "ws"
	case "https":
		u.Scheme = "wss"
	case "ws", "wss":
		// already a websocket URL
	default:
		return "", fmt.Errorf("MYMATE_URL must be http(s):// or ws(s):// (got %q)", u.Scheme)
	}
	if u.Path == "" || u.Path == "/" {
		u.Path = "/agent"
	}
	return u.String(), nil
}

// RedactedURL returns the server URL with no credentials, safe to log.
func (c *Config) RedactedURL() string {
	if u, err := url.Parse(c.ServerURL); err == nil {
		u.User = nil
		return u.String()
	}
	return c.ServerURL
}

func isTruthy(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1", "true", "yes", "on":
		return true
	}
	return false
}
