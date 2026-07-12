package config

import "testing"

func TestToWebSocketURL(t *testing.T) {
	cases := map[string]string{
		"https://app.example.com":       "wss://app.example.com/agent",
		"http://10.0.0.5:8080":          "ws://10.0.0.5:8080/agent",
		"https://app.example.com/agent": "wss://app.example.com/agent",
		"wss://app.example.com/agent":   "wss://app.example.com/agent",
	}
	for in, want := range cases {
		got, err := toWebSocketURL(in)
		if err != nil {
			t.Fatalf("%s: unexpected error %v", in, err)
		}
		if got != want {
			t.Errorf("%s -> %s, want %s", in, got, want)
		}
	}

	if _, err := toWebSocketURL("ftp://nope"); err == nil {
		t.Error("expected an error for a non-http(s)/ws scheme")
	}
}

func TestLoadRequiresURLAndToken(t *testing.T) {
	t.Setenv("MYMATE_URL", "")
	t.Setenv("MYMATE_AGENT_TOKEN", "")
	if _, err := Load(); err == nil {
		t.Error("expected an error when MYMATE_URL/TOKEN are unset")
	}

	t.Setenv("MYMATE_URL", "https://app.example.com")
	t.Setenv("MYMATE_AGENT_TOKEN", "mma_abc")
	cfg, err := Load()
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg.ServerURL != "wss://app.example.com/agent" {
		t.Errorf("ServerURL = %s", cfg.ServerURL)
	}
}
