package agent

import (
	"encoding/json"
	"errors"
	"testing"
)

func TestByeError(t *testing.T) {
	if err := byeError(json.RawMessage(`{"reason":"replaced"}`)); !errors.Is(err, errReplaced) {
		t.Fatalf("replaced bye = %v, want errReplaced", err)
	}
	// Older hubs send a bye with no payload; unknown reasons are a plain close.
	for _, p := range []string{``, `null`, `{"reason":"shutdown"}`} {
		if err := byeError(json.RawMessage(p)); err == nil || errors.Is(err, errReplaced) {
			t.Fatalf("bye %q = %v, want a plain close", p, err)
		}
	}
}
