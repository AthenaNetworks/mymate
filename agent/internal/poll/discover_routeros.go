package poll

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/go-routeros/routeros/v3"
)

// discoverRouterOS reads a MikroTik's interfaces + facts over the RouterOS API so an agent-polled
// RouterOS device is discovered from the agent, not the central server (#33). Facts come back raw;
// the server does the MikroTik-specific derivation (CaptureDeviceFacts::factsFromRouterOsRaw).
func (p *Poller) discoverRouterOS(t proto.RouterOSTarget) *proto.DeviceDiscovery {
	port := t.APIPort
	if port == 0 {
		port = 8728
	}
	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", t.IP, port), t.Username, t.Password, 3*time.Second)
	if err != nil {
		return nil
	}
	defer c.Close()

	ifaces := routerOsInterfaces(c)
	facts := routerOsFacts(c)
	if len(ifaces) == 0 && facts == nil {
		return nil
	}
	return &proto.DeviceDiscovery{DeviceID: t.DeviceID, Interfaces: ifaces, RouterOSFacts: facts}
}

func routerOsInterfaces(c *routeros.Client) []proto.DiscoveredIface {
	reply, err := c.Run("/interface/print")
	if err != nil {
		return nil
	}

	// Best-effort negotiated ethernet speed (Mbps) by name, from /interface/ethernet monitor.
	speeds := map[string]int{}
	if er, err := c.Run("/interface/ethernet/print"); err == nil {
		names := make([]string, 0, len(er.Re))
		for _, re := range er.Re {
			if n := re.Map["name"]; n != "" {
				names = append(names, n)
			}
		}
		if len(names) > 0 {
			if mr, err := c.Run("/interface/ethernet/monitor", "=numbers="+strings.Join(names, ","), "=once="); err == nil {
				for _, re := range mr.Re {
					if mbps := parseSpeedMbps(re.Map["rate"]); mbps > 0 {
						speeds[re.Map["name"]] = mbps
					}
				}
			}
		}
	}

	out := make([]proto.DiscoveredIface, 0, len(reply.Re))
	for _, re := range reply.Re {
		id, name := re.Map[".id"], re.Map["name"]
		if id == "" || name == "" {
			continue
		}
		out = append(out, proto.DiscoveredIface{
			IfIndex:   routerOsIfIndex(id),
			Name:      name,
			SpeedMbps: speeds[name],
			OperUp:    boolFlag(re.Map["running"]),
		})
	}
	return out
}

func routerOsFacts(c *routeros.Client) *proto.RouterOSFacts {
	f := &proto.RouterOSFacts{}
	if r, err := c.Run("/system/resource/print"); err == nil && len(r.Re) > 0 {
		m := r.Re[0].Map
		f.Version = m["version"]
		f.ResBoardName = m["board-name"]
		f.Architecture = m["architecture-name"]
		f.CPU = m["cpu"]
		f.CPUCount = atoiSafe(m["cpu-count"])
		f.CPUFreq = atoiSafe(m["cpu-frequency"])
		f.TotalMemory = atouSafe(m["total-memory"])
		f.Uptime = m["uptime"]
	}
	if r, err := c.Run("/system/routerboard/print"); err == nil && len(r.Re) > 0 {
		m := r.Re[0].Map
		f.Model = m["model"]
		f.BoardName = m["board-name"]
		f.Serial = m["serial-number"]
	}
	// /snmp carries the location string the operator sets for the geo map; harmless if disabled.
	if r, err := c.Run("/snmp/print"); err == nil && len(r.Re) > 0 {
		f.Location = r.Re[0].Map["location"]
	}

	if f.Version == "" && f.Model == "" && f.BoardName == "" && f.Serial == "" && f.Location == "" {
		return nil
	}
	return f
}

// routerOsIfIndex turns a RouterOS `.id` like "*A" into a stable integer (hex), matching the
// central RouterOS driver so agent-discovered and centrally-polled indexes line up.
func routerOsIfIndex(id string) int {
	n, _ := strconv.ParseInt(strings.TrimPrefix(id, "*"), 16, 64)
	return int(n)
}

// boolFlag parses a RouterOS boolean ("true"/"false", older "yes"/"no") to a tri-state pointer.
func boolFlag(v string) *bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "true", "yes":
		b := true
		return &b
	case "false", "no":
		b := false
		return &b
	default:
		return nil
	}
}

// parseSpeedMbps: "1Gbps"->1000, "100Mbps"->100, "10Gbps"->10000; 0 if unparseable.
func parseSpeedMbps(rate string) int {
	low := strings.TrimSuffix(strings.ToLower(strings.TrimSpace(rate)), "bps")
	if low == "" {
		return 0
	}
	mult := 1.0
	switch {
	case strings.HasSuffix(low, "g"):
		mult, low = 1000, strings.TrimSuffix(low, "g")
	case strings.HasSuffix(low, "m"):
		mult, low = 1, strings.TrimSuffix(low, "m")
	case strings.HasSuffix(low, "k"):
		mult, low = 0.001, strings.TrimSuffix(low, "k")
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(low), 64)
	if err != nil {
		return 0
	}
	return int(f * mult)
}

func atoiSafe(s string) int {
	n, _ := strconv.Atoi(strings.TrimSpace(s))
	return n
}

func atouSafe(s string) uint64 {
	n, _ := strconv.ParseUint(strings.TrimSpace(s), 10, 64)
	return n
}
