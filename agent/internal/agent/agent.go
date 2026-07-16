// Package agent implements the remote agent's runtime: an outbound WebSocket to the central
// app that it keeps alive (reconnecting with exponential backoff), over which it receives
// poll/scan jobs, executes them locally (ICMP/SNMP), and returns results.
package agent

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/config"
	"github.com/AthenaNetworks/mymate/agent/internal/poll"
	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gorilla/websocket"
)

const (
	handshakeTimeout = 15 * time.Second
	pingPeriod       = 30 * time.Second // how often we send a keepalive ping
	pongWait         = 45 * time.Second // no traffic within this -> treat the link as dead
	writeWait        = 10 * time.Second
)

// Run connects to the hub and stays connected, reconnecting forever until ctx is cancelled.
// The poller (with its per-interface counter state for bps deltas) outlives reconnects.
func Run(ctx context.Context, cfg *config.Config) error {
	b := &backoff{min: time.Second, max: 60 * time.Second}
	poller := poll.New()

	for {
		if err := ctx.Err(); err != nil {
			return err
		}

		err := serve(ctx, cfg, b, poller)
		if ctxErr := ctx.Err(); ctxErr != nil {
			return ctxErr
		}

		wait := b.next()
		slog.Warn("disconnected from hub; reconnecting", "error", err, "retry_in", wait.Round(time.Millisecond))
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
	}
}

// serve runs a single connection lifecycle: dial, hello, then read + keepalive until the
// link drops or ctx is cancelled. A single writer goroutine serialises all data writes
// (gorilla requires one concurrent writer), so poll goroutines can safely queue results.
func serve(ctx context.Context, cfg *config.Config, b *backoff, poller *poll.Poller) error {
	dialer := websocket.Dialer{
		HandshakeTimeout: handshakeTimeout,
		TLSClientConfig:  &tls.Config{InsecureSkipVerify: cfg.InsecureTLS}, //nolint:gosec // opt-in for self-signed dev
	}
	header := http.Header{}
	header.Set("Authorization", "Bearer "+cfg.Token)
	header.Set("User-Agent", "mymate-agent/"+Version)

	conn, resp, err := dialer.DialContext(ctx, cfg.ServerURL, header)
	if err != nil {
		return dialError(err, resp)
	}
	defer conn.Close()

	b.reset()
	slog.Info("connected to hub", "server", cfg.RedactedURL())

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	send := make(chan []byte, 64)
	go writer(ctx, conn, send)
	go keepalive(ctx, conn)

	// Announce ourselves.
	send <- mustJSON(proto.Hello{Type: "hello", Version: Version, Platform: Platform(), Name: cfg.Name})

	// Keepalive read deadline, advanced on every pong.
	conn.SetReadLimit(4 << 20)
	_ = conn.SetReadDeadline(time.Now().Add(pongWait))
	conn.SetPongHandler(func(string) error { return conn.SetReadDeadline(time.Now().Add(pongWait)) })

	for {
		_, data, err := conn.ReadMessage()
		if err != nil {
			return err
		}
		var msg proto.Inbound
		if err := json.Unmarshal(data, &msg); err != nil {
			slog.Warn("ignoring malformed message from hub", "error", err)
			continue
		}
		handle(ctx, poller, send, msg)
	}
}

// handle dispatches an inbound message. Polling runs in its own goroutine so a slow SNMP
// device can't stall the read loop / keepalive; the result is queued on the send channel.
func handle(ctx context.Context, poller *poll.Poller, send chan<- []byte, msg proto.Inbound) {
	switch msg.Type {
	case "poll":
		var job proto.PollJob
		if err := json.Unmarshal(msg.Payload, &job); err != nil {
			slog.Warn("bad poll job", "error", err)
			return
		}
		go func() {
			result := poller.Run(ctx, job)
			select {
			case send <- mustJSON(proto.Result{Type: "result", Payload: mustJSON(result)}):
			case <-ctx.Done():
			}
		}()
	case "scan":
		var job proto.ScanJob
		if err := json.Unmarshal(msg.Payload, &job); err != nil {
			slog.Warn("bad scan job", "error", err)
			return
		}
		go func() {
			// Stream live scan feedback (scan_start / scan_progress) as it runs; drop a progress
			// tick if the send buffer is momentarily full rather than stalling the sweep.
			emit := func(v any) {
				select {
				case send <- mustJSON(v):
				default:
				}
			}
			result := poller.Scan(ctx, job, emit)
			select {
			case send <- mustJSON(proto.Result{Type: "discovery", Payload: mustJSON(result)}):
			case <-ctx.Done():
			}
		}()
	case "bye":
		slog.Info("hub closed the session")
	default:
		slog.Debug("ignoring unknown message type", "type", msg.Type)
	}
}

// writer is the sole data-frame writer for a connection.
func writer(ctx context.Context, conn *websocket.Conn, send <-chan []byte) {
	for {
		select {
		case <-ctx.Done():
			return
		case b := <-send:
			_ = conn.SetWriteDeadline(time.Now().Add(writeWait))
			if err := conn.WriteMessage(websocket.TextMessage, b); err != nil {
				return
			}
		}
	}
}

func keepalive(ctx context.Context, conn *websocket.Conn) {
	t := time.NewTicker(pingPeriod)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			// WriteControl is safe concurrently with the data writer.
			if err := conn.WriteControl(websocket.PingMessage, nil, time.Now().Add(writeWait)); err != nil {
				return
			}
		}
	}
}

func mustJSON(v any) []byte {
	b, _ := json.Marshal(v)
	return b
}

func dialError(err error, resp *http.Response) error {
	if resp != nil {
		switch resp.StatusCode {
		case http.StatusUnauthorized, http.StatusForbidden:
			return fmt.Errorf("hub rejected the token (HTTP %d) - check MYMATE_AGENT_TOKEN", resp.StatusCode)
		case http.StatusNotFound:
			return fmt.Errorf("hub endpoint not found (HTTP 404) - check MYMATE_URL")
		}
		return fmt.Errorf("handshake failed (HTTP %d): %w", resp.StatusCode, err)
	}
	return fmt.Errorf("connect: %w", err)
}

// backoff is exponential with full jitter, capped.
type backoff struct {
	min, max time.Duration
	cur      time.Duration
}

func (b *backoff) next() time.Duration {
	if b.cur == 0 {
		b.cur = b.min
	} else {
		b.cur *= 2
		if b.cur > b.max {
			b.cur = b.max
		}
	}
	return b.min + time.Duration(rand.Int64N(int64(b.cur-b.min)+1))
}

func (b *backoff) reset() { b.cur = 0 }
