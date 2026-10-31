package upgrade

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

func newManager(t *testing.T, base string) *Manager {
	t.Helper()
	t.Setenv(EnvDir, t.TempDir())
	m := New(base)
	if m == nil {
		t.Fatal("expected a manager for a writable state dir")
	}
	return m
}

// fakeBinary is a shell script that answers `version` like the real agent.
func fakeBinary(version string) []byte {
	return []byte("#!/bin/sh\necho 'mymate-agent " + version + " (test)'\n")
}

func sum(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }

func TestNewer(t *testing.T) {
	cases := []struct {
		a, b string
		want bool
	}{
		{"v1.10.0", "v1.9.0", true},
		{"v1.9.0", "v1.10.0", false},
		{"v1.9.0", "v1.9.0", false},
		{"v2.0.0", "1.99.99", true},
		{"v1.9.0", "dev", true}, // a release beats a dev build
		{"dev", "v1.0.0", false},
		{"v1.9.1-rc1", "v1.9.0", true},
	}
	for _, c := range cases {
		if got := Newer(c.a, c.b); got != c.want {
			t.Errorf("Newer(%q, %q) = %v, want %v", c.a, c.b, got, c.want)
		}
	}
}

func TestDisabledOrUnwritableMeansNoManager(t *testing.T) {
	t.Setenv(EnvDisable, "1")
	t.Setenv(EnvDir, t.TempDir())
	if New("v1.0.0") != nil {
		t.Fatal("self-upgrade should be off when disabled")
	}
	t.Setenv(EnvDisable, "")
	file := filepath.Join(t.TempDir(), "not-a-dir")
	_ = os.WriteFile(file, nil, 0o600)
	t.Setenv(EnvDir, file)
	if New("v1.0.0") != nil {
		t.Fatal("self-upgrade should be off without a writable state dir")
	}
}

func stage(t *testing.T, m *Manager, version string) {
	t.Helper()
	p := m.BinaryPath(version)
	_ = os.MkdirAll(filepath.Dir(p), 0o755)
	_ = os.WriteFile(p, fakeBinary(version), 0o755)
	m.update(func(s *State) { s.Pending = version; s.Attempts = 0 })
}

func TestLifecycle(t *testing.T) {
	m := newManager(t, "v1.0.0")
	if m.Choose() != "" {
		t.Fatal("nothing staged: run the base")
	}

	stage(t, m, "v1.1.0")
	if m.Choose() != "v1.1.0" {
		t.Fatal("a pending version is tried")
	}
	for i := 0; i < MaxAttempts; i++ {
		m.BeginAttempt("v1.1.0")
	}
	if m.Choose() != "" {
		t.Fatal("out of attempts: back to the base")
	}

	m.MarkBad("v1.1.0", "boom")
	if r := m.TakeReport(); r == nil || r.Stage != StageRolledBack || r.Version != "v1.1.0" || r.Message != "boom" {
		t.Fatalf("rollback report = %+v", r)
	}
	if m.TakeReport() != nil {
		t.Fatal("a report is sent once")
	}
	if _, err := os.Stat(m.BinaryPath("v1.1.0")); !os.IsNotExist(err) {
		t.Fatal("a bad version's binary is removed")
	}

	stage(t, m, "v1.2.0")
	m.BeginAttempt("v1.2.0")
	m.MarkHealthy("v1.2.0")
	if s := m.Load(); s.Current != "v1.2.0" || s.Pending != "" || s.Attempts != 0 {
		t.Fatalf("after healthy: %+v", s)
	}
	if r := m.TakeReport(); r == nil || r.Stage != StageDone {
		t.Fatalf("healthy report = %+v", r)
	}
	if m.Choose() != "v1.2.0" {
		t.Fatal("the healthy staged version keeps running")
	}

	// The package manager upgraded the base past it: the base wins.
	m.BaseVersion = "v1.3.0"
	if m.Choose() != "" {
		t.Fatal("an older staged version never overrides a newer base")
	}
}

func TestStageDownloadsVerifiesAndRefuses(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("uses a shell script as the fake binary")
	}
	good := fakeBinary("v2.0.0")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer tok" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		switch r.URL.Path {
		case "/agent/binary/v2.0.0/linux_amd64":
			_, _ = w.Write(good)
		case "/agent/binary/v2.0.1/linux_amd64":
			_, _ = w.Write(fakeBinary("v9.9.9")) // claims a different version
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()
	ws := "ws" + strings.TrimPrefix(srv.URL, "http") + "/agent"
	ctx := context.Background()

	m := newManager(t, "v1.0.0")
	ok := Target{Version: "v2.0.0", Path: "/agent/binary/v2.0.0/linux_amd64", SHA256: sum(good), Size: int64(len(good))}
	if err := m.Stage(ctx, ok, ws, "tok", false); err != nil {
		t.Fatalf("stage: %v", err)
	}
	if m.Load().Pending != "v2.0.0" || m.Choose() != "v2.0.0" {
		t.Fatal("a verified download becomes pending")
	}

	bad := ok
	bad.Version = "v2.0.2"
	bad.SHA256 = strings.Repeat("0", 64)
	if err := m.Stage(ctx, bad, ws, "tok", false); err == nil {
		t.Fatal("a checksum mismatch must be refused")
	}

	lying := Target{Version: "v2.0.1", Path: "/agent/binary/v2.0.1/linux_amd64", Size: int64(len(fakeBinary("v9.9.9"))), SHA256: sum(fakeBinary("v9.9.9"))}
	if err := m.Stage(ctx, lying, ws, "tok", false); err == nil || !strings.Contains(err.Error(), "reports") {
		t.Fatalf("a binary reporting the wrong version must be refused, got %v", err)
	}

	elsewhere := ok
	elsewhere.Path = "https://evil.example/agent"
	if err := m.Stage(ctx, elsewhere, ws, "tok", false); err == nil {
		t.Fatal("only paths on the agent's own server are fetched")
	}

	if m.Load().Pending != "v2.0.0" {
		t.Fatal("failed attempts leave the good pending version alone")
	}
}

func TestDownloadURLKeepsTheAgentsOwnServer(t *testing.T) {
	got, err := downloadURL("wss://mon.example.com/sub/agent", "/agent/binary/v1/linux_amd64")
	if err != nil || got != "https://mon.example.com/sub/agent/binary/v1/linux_amd64" {
		t.Fatalf("got %q, %v", got, err)
	}
	if _, err := downloadURL("wss://mon.example.com/agent", "/agent/binary/../../etc/passwd"); err == nil {
		t.Fatal("path traversal refused")
	}
}

// A staged version that keeps failing is rolled back and the base runs: the agent ends up online.
func TestSuperviseRollsBackAFailingVersion(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("uses sh")
	}
	m := newManager(t, "v1.0.0")
	stage(t, m, "v1.1.0")
	restartBackoff = time.Millisecond
	starts := 0
	childCommand = func(ctx context.Context, bin, version string) *exec.Cmd {
		starts++
		return exec.CommandContext(ctx, "sh", "-c", "exit 11") // never gets healthy
	}

	errBase := errors.New("base ran")
	err := Supervise(context.Background(), m, func(context.Context) error { return errBase })
	if !errors.Is(err, errBase) {
		t.Fatalf("expected to fall back to the base, got %v", err)
	}
	if starts != MaxAttempts {
		t.Fatalf("started the failing version %d times, want %d", starts, MaxAttempts)
	}
	if r := m.TakeReport(); r == nil || r.Stage != StageRolledBack {
		t.Fatalf("rollback must be reported, got %+v", r)
	}
	if m.Choose() != "" {
		t.Fatal("a rolled-back version is never chosen again")
	}
}

// The base staging an upgrade hands over to it.
func TestSuperviseRestartsIntoAStagedVersion(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("uses sh")
	}
	m := newManager(t, "v1.0.0")
	ran := []string{}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	childCommand = func(ctx2 context.Context, bin, version string) *exec.Cmd {
		ran = append(ran, version)
		m.MarkHealthy(version)
		cancel() // the service is stopped while the new version runs
		return exec.CommandContext(ctx2, "sh", "-c", "exit 0")
	}
	calls := 0
	_ = Supervise(ctx, m, func(context.Context) error {
		calls++
		if calls > 1 {
			t.Error("the base shouldn't run again while a healthy staged version exists")
			return context.Canceled
		}
		stage(t, m, "v1.1.0") // what handling an "upgrade" does
		return ErrRestart
	})
	if len(ran) == 0 || ran[0] != "v1.1.0" {
		t.Fatalf("expected the staged version to run, ran %v", ran)
	}
	if m.Load().Current != "v1.1.0" {
		t.Fatal("it became the current version")
	}
}
