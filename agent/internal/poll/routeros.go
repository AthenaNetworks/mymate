package poll

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/go-routeros/routeros/v3"
)

// pollRouterOS reads throughput from a MikroTik over the RouterOS API using
// `/interface/monitor-traffic once`, which returns rx/tx bits-per-second directly (no
// counter delta needed - same as the central RouterOS driver). Matched to our interfaces
// by name.
func (p *Poller) pollRouterOS(t proto.RouterOSTarget) []proto.FlowResult {
	if len(t.Interfaces) == 0 {
		return nil
	}
	port := t.APIPort
	if port == 0 {
		port = 8728
	}

	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", t.IP, port), t.Username, t.Password, 3*time.Second)
	if err != nil {
		return nil // filtered port / bad creds - skip this device, don't sink the batch
	}
	defer c.Close()

	byName := make(map[string]int, len(t.Interfaces))
	names := make([]string, 0, len(t.Interfaces))
	for _, i := range t.Interfaces {
		if i.Name == "" {
			continue
		}
		byName[i.Name] = i.InterfaceID
		names = append(names, i.Name)
	}
	if len(names) == 0 {
		return nil
	}

	reply, err := c.Run("/interface/monitor-traffic", "=interface="+strings.Join(names, ","), "=once=")
	if err != nil {
		return nil
	}

	flows := make([]proto.FlowResult, 0, len(reply.Re))
	for _, re := range reply.Re {
		id, ok := byName[re.Map["name"]]
		if !ok {
			continue
		}
		flows = append(flows, proto.FlowResult{
			InterfaceID: id,
			InBps:       parseFloat(re.Map["rx-bits-per-second"]),
			OutBps:      parseFloat(re.Map["tx-bits-per-second"]),
		})
	}
	return flows
}

func parseFloat(s string) float64 {
	f, _ := strconv.ParseFloat(strings.TrimSpace(s), 64)
	return f
}
