// Package upgrade lets the hub upgrade an agent in place, safely.
//
// The installed binary (the "base") is never overwritten - a package manager owns it, and on
// systemd it lives on a read-only filesystem anyway. A new version is downloaded into the agent's
// own state directory, checked (size, SHA-256, and that it runs and reports the right version),
// and only then run - as a child of the base, which supervises it. A version that doesn't reach
// a stable hub connection within a few starts is marked bad and the agent falls back to the last
// version that worked, so a failed upgrade always ends with the agent back online.
//
// State is one small JSON file next to the staged binaries:
//
//	<state>/upgrade.json
//	<state>/bin/<version>/mymate-agent[.exe]
package upgrade

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"time"
)

const (
	// MaxAttempts is how many starts a staged version gets to become healthy before it's
	// marked bad and the agent falls back.
	MaxAttempts = 3
	// HealthyAfter: connected to the hub and stayed connected this long = the version works.
	HealthyAfter = 60 * time.Second
	// HealthDeadline: a staged version that hasn't become healthy by now gives up (the
	// supervisor counts it as a failed start).
	HealthDeadline = 5 * time.Minute

	maxBinarySize = 128 << 20
	stateFile     = "upgrade.json"
)

// Env vars between the supervising base and its staged child.
const (
	EnvStaged  = "MYMATE_AGENT_STAGED" // set on the child: "<version>"
	EnvDisable = "MYMATE_AGENT_NO_SELF_UPGRADE"
	EnvDir     = "MYMATE_AGENT_STATE_DIR"
)

// Stage names reported to the hub (agent -> server "upgrade_status").
const (
	StageDownloading = "downloading"
	StageRestarting  = "restarting"
	StageDone        = "done"
	StageFailed      = "failed"
	StageRolledBack  = "rolled_back"
)

var versionRe = regexp.MustCompile(`^v?[0-9A-Za-z][0-9A-Za-z.\-+]{0,39}$`)

// Report is an outcome the agent tells the hub once it's connected.
type Report struct {
	Stage   string `json:"stage"`
	Version string `json:"version"`
	Message string `json:"message,omitempty"`
}

// State is upgrade.json.
type State struct {
	Pending  string   `json:"pending,omitempty"`  // staged, not yet proven healthy
	Current  string   `json:"current,omitempty"`  // staged and proven healthy
	Attempts int      `json:"attempts,omitempty"` // starts of the version being tried without becoming healthy
	Bad      []string `json:"bad,omitempty"`      // versions that failed - never run again
	Report   *Report  `json:"report,omitempty"`   // to send to the hub on the next connect
}

// Manager owns the state directory. A nil *Manager means self-upgrade isn't available here.
type Manager struct {
	Dir         string
	BaseVersion string
}

// New returns a Manager when this agent can upgrade itself: not disabled, and a state directory
// it can write to. Otherwise nil, and the agent simply doesn't advertise the capability.
func New(baseVersion string) *Manager {
	if os.Getenv(EnvDisable) != "" {
		return nil
	}
	dir := Dir()
	if dir == "" || os.MkdirAll(filepath.Join(dir, "bin"), 0o755) != nil {
		return nil
	}
	probe := filepath.Join(dir, ".write-test")
	if os.WriteFile(probe, nil, 0o600) != nil {
		return nil
	}
	_ = os.Remove(probe)
	return &Manager{Dir: dir, BaseVersion: baseVersion}
}

// Dir is the agent's state directory: MYMATE_AGENT_STATE_DIR, else the one systemd made for
// it (StateDirectory=), else the platform default.
func Dir() string {
	if d := strings.TrimSpace(os.Getenv(EnvDir)); d != "" {
		return d
	}
	if d := strings.TrimSpace(os.Getenv("STATE_DIRECTORY")); d != "" {
		return strings.Split(d, ":")[0]
	}
	switch runtime.GOOS {
	case "windows":
		base := os.Getenv("ProgramData")
		if base == "" {
			base = `C:\ProgramData`
		}
		return filepath.Join(base, "mymate-agent", "state")
	case "darwin":
		return "/Library/Application Support/mymate-agent"
	default:
		return "/var/lib/mymate-agent"
	}
}

func binName() string {
	if runtime.GOOS == "windows" {
		return "mymate-agent.exe"
	}
	return "mymate-agent"
}

// BinaryPath is where a staged version lives.
func (m *Manager) BinaryPath(version string) string {
	return filepath.Join(m.Dir, "bin", version, binName())
}

func (m *Manager) Load() State {
	var s State
	b, err := os.ReadFile(filepath.Join(m.Dir, stateFile))
	if err == nil {
		_ = json.Unmarshal(b, &s)
	}
	return s
}

func (m *Manager) Save(s State) error {
	b, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	tmp := filepath.Join(m.Dir, stateFile+".tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(m.Dir, stateFile))
}

func (m *Manager) update(f func(*State)) {
	s := m.Load()
	f(&s)
	_ = m.Save(s)
}

// Choose picks what the supervisor should run: a pending version still within its attempts,
// else the last healthy staged version if it's newer than the base. "" = run the base itself.
func (m *Manager) Choose() string {
	s := m.Load()
	usable := func(v string) bool {
		return v != "" && !slices.Contains(s.Bad, v) && fileExists(m.BinaryPath(v))
	}
	if usable(s.Pending) && s.Attempts < MaxAttempts {
		return s.Pending
	}
	if usable(s.Current) && Newer(s.Current, m.BaseVersion) {
		return s.Current
	}
	return ""
}

// BeginAttempt records that the supervisor is about to start version. Counted before the start,
// so even a binary that dies before running any of our code is counted.
func (m *Manager) BeginAttempt(version string) int {
	n := 0
	m.update(func(s *State) {
		s.Attempts++
		n = s.Attempts
	})
	return n
}

// MarkHealthy: version reached a stable hub connection. It becomes the version to run.
func (m *Manager) MarkHealthy(version string) {
	m.update(func(s *State) {
		wasPending := s.Pending == version
		s.Attempts = 0
		if wasPending {
			s.Pending = ""
			s.Current = version
			s.Report = &Report{Stage: StageDone, Version: version}
		}
	})
}

// MarkBad: version failed. It's never run again, and the agent falls back to whatever Choose
// picks next. The reason is reported to the hub on the next connect.
func (m *Manager) MarkBad(version, reason string) {
	m.update(func(s *State) {
		if !slices.Contains(s.Bad, version) {
			s.Bad = append(s.Bad, version)
		}
		if s.Pending == version {
			s.Pending = ""
		}
		if s.Current == version {
			s.Current = ""
		}
		s.Attempts = 0
		s.Report = &Report{Stage: StageRolledBack, Version: version, Message: reason}
	})
	_ = os.RemoveAll(filepath.Dir(m.BinaryPath(version)))
}

// SetReport records an outcome to tell the hub (a failed download, say).
func (m *Manager) SetReport(r Report) { m.update(func(s *State) { s.Report = &r }) }

// TakeReport returns the pending outcome, if any, and clears it.
func (m *Manager) TakeReport() *Report {
	var r *Report
	m.update(func(s *State) { r, s.Report = s.Report, nil })
	return r
}

// Target is an upgrade the hub asked for.
type Target struct {
	Version string `json:"version"`
	Path    string `json:"path"` // on the hub, e.g. /agent/binary/v1.10.0/linux_amd64
	SHA256  string `json:"sha256"`
	Size    int64  `json:"size"`
}

// Stage downloads, verifies and stages t. On success t.Version is pending and the caller should
// restart into it. serverURL is the agent's own ws(s):// hub URL: the binary is fetched from the
// same server, never a host the hub names.
func (m *Manager) Stage(ctx context.Context, t Target, serverURL, token string, insecureTLS bool) error {
	if !versionRe.MatchString(t.Version) {
		return fmt.Errorf("invalid version %q", t.Version)
	}
	if slices.Contains(m.Load().Bad, t.Version) {
		return fmt.Errorf("%s failed here before and won't be tried again", t.Version)
	}
	if t.Size <= 0 || t.Size > maxBinarySize || len(t.SHA256) != 64 {
		return errors.New("upgrade is missing its size or checksum")
	}
	src, err := downloadURL(serverURL, t.Path)
	if err != nil {
		return err
	}

	dest := m.BinaryPath(t.Version)
	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}
	tmp := dest + ".download"
	defer os.Remove(tmp)
	if err := download(ctx, src, token, insecureTLS, tmp, t.Size, t.SHA256); err != nil {
		return err
	}
	if err := os.Chmod(tmp, 0o755); err != nil {
		return err
	}
	// It must actually run on this machine and say it's the version we asked for.
	if err := checkVersion(ctx, tmp, t.Version); err != nil {
		return err
	}
	if err := os.Rename(tmp, dest); err != nil {
		return err
	}
	m.update(func(s *State) {
		s.Pending = t.Version
		s.Attempts = 0
	})
	return nil
}

// downloadURL resolves the hub-relative path against the agent's own server, refusing anything
// that isn't a binary path on that same server.
func downloadURL(serverURL, path string) (string, error) {
	if !strings.HasPrefix(path, "/agent/binary/") || strings.Contains(path, "..") {
		return "", fmt.Errorf("refusing download path %q", path)
	}
	u, err := url.Parse(serverURL)
	if err != nil {
		return "", err
	}
	switch u.Scheme {
	case "wss":
		u.Scheme = "https"
	case "ws":
		u.Scheme = "http"
	}
	// Keep any prefix the agent's URL has before /agent (an install under a sub-path).
	prefix := strings.TrimSuffix(strings.TrimSuffix(u.Path, "/"), "/agent")
	u.Path = prefix + path
	u.RawQuery = ""
	return u.String(), nil
}

func download(ctx context.Context, src, token string, insecureTLS bool, dest string, size int64, sum string) error {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, src, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	client := &http.Client{Transport: &http.Transport{
		Proxy:           http.ProxyFromEnvironment,
		TLSClientConfig: &tls.Config{InsecureSkipVerify: insecureTLS}, //nolint:gosec // same opt-in as the hub connection
	}}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download: HTTP %d", resp.StatusCode)
	}

	f, err := os.OpenFile(dest, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, size+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	if n != size {
		return fmt.Errorf("download: got %d bytes, expected %d", n, size)
	}
	if got := hex.EncodeToString(h.Sum(nil)); !strings.EqualFold(got, sum) {
		return fmt.Errorf("download: checksum mismatch (got %s)", got)
	}
	return nil
}

// checkVersion runs `<bin> version` and checks it reports want.
func checkVersion(ctx context.Context, bin, want string) error {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	out, err := exec.CommandContext(ctx, bin, "version").Output()
	if err != nil {
		return fmt.Errorf("the new binary doesn't run here: %w", err)
	}
	fields := strings.Fields(string(out))
	if len(fields) < 2 || fields[1] != want {
		return fmt.Errorf("the new binary reports %q, expected %s", strings.TrimSpace(string(out)), want)
	}
	return nil
}

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}

// Newer reports whether version a is newer than b. Versions are vMAJOR.MINOR.PATCH; anything
// that doesn't parse (a "dev" build) counts as older than any release.
func Newer(a, b string) bool {
	pa, okA := parse(a)
	pb, okB := parse(b)
	switch {
	case !okA:
		return false
	case !okB:
		return true
	}
	for i := range pa {
		if pa[i] != pb[i] {
			return pa[i] > pb[i]
		}
	}
	return false
}

func parse(v string) ([3]int, bool) {
	var out [3]int
	v = strings.TrimPrefix(v, "v")
	v, _, _ = strings.Cut(v, "-")
	parts := strings.Split(v, ".")
	if len(parts) != 3 {
		return out, false
	}
	for i, p := range parts {
		n, err := strconv.Atoi(p)
		if err != nil {
			return out, false
		}
		out[i] = n
	}
	return out, true
}
