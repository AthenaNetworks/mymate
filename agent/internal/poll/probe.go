package poll

import (
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
)

// runProbe executes one service probe (#19) from the agent's own network and returns the verdict.
// The server owns the status/dampening/alerting decision; the agent just reports up/latency/
// message/cert. Fixes #33 for probes: an agent-device's HTTP/TCP check no longer runs centrally.
func (p *Poller) runProbe(t proto.ProbeTarget) proto.ProbeCheck {
	switch t.Kind {
	case "http":
		return httpProbe(t)
	case "tcp":
		return tcpProbe(t)
	default:
		return proto.ProbeCheck{ProbeID: t.ProbeID, Message: "unknown probe kind"}
	}
}

func probeTimeout(ms int) time.Duration {
	if ms <= 0 {
		ms = 5000
	}
	return time.Duration(ms) * time.Millisecond
}

// httpProbe mirrors the server's HttpProbe: no redirects (a 301/302 is a real answer), the
// expected-status expression decides up/down, an optional body keyword, and the TLS cert expiry.
func httpProbe(t proto.ProbeTarget) proto.ProbeCheck {
	res := proto.ProbeCheck{ProbeID: t.ProbeID}
	if strings.TrimSpace(t.URL) == "" {
		res.Message = "no url configured"
		return res
	}
	method := strings.ToUpper(t.Method)
	if method == "" {
		method = "GET"
	}
	expect := t.ExpectStatus
	if expect == "" {
		expect = "200-399"
	}
	keyword := strings.TrimSpace(t.ExpectBody)

	client := &http.Client{
		Timeout:       probeTimeout(t.TimeoutMs),
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport: &http.Transport{
			TLSClientConfig:   &tls.Config{InsecureSkipVerify: !t.VerifyTLS},
			DisableKeepAlives: true,
		},
	}

	req, err := http.NewRequest(method, t.URL, nil)
	if err != nil {
		res.Message = "request failed"
		return res
	}

	start := time.Now()
	resp, err := client.Do(req)
	lat := msSince(start)
	res.LatencyMs = &lat
	if err != nil {
		res.Message = httpReason(err)
		return res
	}
	defer resp.Body.Close()

	if resp.TLS != nil && len(resp.TLS.PeerCertificates) > 0 {
		exp := resp.TLS.PeerCertificates[0].NotAfter.Unix()
		res.CertExpires = &exp
	}

	if !statusMatches(resp.StatusCode, expect) {
		res.Message = fmt.Sprintf("HTTP %d", resp.StatusCode)
		return res
	}
	if keyword != "" && method != "HEAD" {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512*1024))
		if !strings.Contains(strings.ToLower(string(body)), strings.ToLower(keyword)) {
			res.Message = fmt.Sprintf("HTTP %d, missing %q", resp.StatusCode, keyword)
			return res
		}
	}

	res.Up = true
	res.Message = fmt.Sprintf("HTTP %d", resp.StatusCode)
	return res
}

func tcpProbe(t proto.ProbeTarget) proto.ProbeCheck {
	res := proto.ProbeCheck{ProbeID: t.ProbeID}
	if t.Host == "" || t.Port < 1 || t.Port > 65535 {
		res.Message = "invalid host/port"
		return res
	}
	start := time.Now()
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(t.Host, strconv.Itoa(t.Port)), probeTimeout(t.TimeoutMs))
	lat := msSince(start)
	res.LatencyMs = &lat
	if err != nil {
		res.Message = tcpReason(err)
		return res
	}
	conn.Close()
	res.Up = true
	res.Message = fmt.Sprintf("port %d open", t.Port)
	return res
}

func msSince(t time.Time) float64 { return float64(time.Since(t).Microseconds()) / 1000.0 }

// statusMatches mirrors HttpProbe::statusMatches: a comma list of exact codes ("200,204"),
// inclusive ranges ("200-399") and single-digit wildcards ("2xx").
func statusMatches(status int, expect string) bool {
	for _, part := range strings.Split(expect, ",") {
		part = strings.ToLower(strings.TrimSpace(part))
		switch {
		case part == "":
			continue
		case strings.Contains(part, "-"):
			b := strings.SplitN(part, "-", 2)
			lo, _ := strconv.Atoi(strings.TrimSpace(b[0]))
			hi, _ := strconv.Atoi(strings.TrimSpace(b[1]))
			if status >= lo && status <= hi {
				return true
			}
		case strings.Contains(part, "x"):
			prefix := strings.TrimRight(part, "x")
			s := strconv.Itoa(status)
			if prefix != "" && strings.HasPrefix(s, prefix) && len(s) == len(part) {
				return true
			}
		default:
			if n, err := strconv.Atoi(part); err == nil && n == status {
				return true
			}
		}
	}
	return false
}

func httpReason(err error) string {
	s := strings.ToLower(err.Error())
	switch {
	case strings.Contains(s, "timeout") || strings.Contains(s, "deadline exceeded"):
		return "timed out"
	case strings.Contains(s, "no such host") || strings.Contains(s, "name resolution"):
		return "dns lookup failed"
	case strings.Contains(s, "refused"):
		return "connection refused"
	case strings.Contains(s, "certificate") || strings.Contains(s, "x509") || strings.Contains(s, "tls"):
		return "tls error"
	default:
		return "request failed"
	}
}

func tcpReason(err error) string {
	s := strings.ToLower(err.Error())
	switch {
	case strings.Contains(s, "refused"):
		return "connection refused"
	case strings.Contains(s, "timeout") || strings.Contains(s, "deadline"):
		return "timed out"
	default:
		return "unreachable"
	}
}
