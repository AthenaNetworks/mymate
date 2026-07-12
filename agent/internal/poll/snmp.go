package poll

import (
	"strconv"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// ifXTable 64-bit HC counters (same OIDs the central SNMP driver uses).
const (
	oidInOctets  = "1.3.6.1.2.1.31.1.1.1.6."  // ifHCInOctets.<ifIndex>
	oidOutOctets = "1.3.6.1.2.1.31.1.1.1.10." // ifHCOutOctets.<ifIndex>
)

// pollSNMP reads each interface's HC octet counters over SNMP v2c and turns consecutive
// samples into bits/sec. Interfaces with no prior sample (or a counter reset) yield no rate
// this tick - exactly like the central path's first poll.
func (p *Poller) pollSNMP(t proto.SNMPTarget) []proto.FlowResult {
	if len(t.Interfaces) == 0 {
		return nil
	}

	g := &gosnmp.GoSNMP{
		Target:    t.IP,
		Port:      161,
		Community: t.Community,
		Version:   gosnmp.Version2c,
		Timeout:   2 * time.Second,
		Retries:   1,
		MaxOids:   60,
	}
	if err := g.Connect(); err != nil {
		return nil
	}
	defer g.Conn.Close()

	oids := make([]string, 0, len(t.Interfaces)*2)
	for _, i := range t.Interfaces {
		idx := strconv.Itoa(i.IfIndex)
		oids = append(oids, oidInOctets+idx, oidOutOctets+idx)
	}

	counters := map[string]uint64{}
	for _, chunk := range chunk(oids, 60) {
		res, err := g.Get(chunk)
		if err != nil {
			continue // a black-holing device shouldn't sink the whole batch
		}
		for _, v := range res.Variables {
			counters[normalise(v.Name)] = gosnmp.ToBigInt(v.Value).Uint64()
		}
	}

	now := time.Now()
	flows := make([]proto.FlowResult, 0, len(t.Interfaces))
	for _, i := range t.Interfaces {
		idx := strconv.Itoa(i.IfIndex)
		in, okIn := counters[oidInOctets+idx]
		out, okOut := counters[oidOutOctets+idx]
		if !okIn || !okOut {
			continue
		}
		inBps, outBps := p.state.rate(i.InterfaceID, in, out, now)
		if inBps == nil {
			continue // first sample or reset - no rate yet
		}
		flows = append(flows, proto.FlowResult{InterfaceID: i.InterfaceID, InBps: *inBps, OutBps: *outBps})
	}
	return flows
}

// gosnmp returns OID names with a leading dot; our keys don't - strip it.
func normalise(oid string) string {
	if len(oid) > 0 && oid[0] == '.' {
		return oid[1:]
	}
	return oid
}

func chunk(s []string, n int) [][]string {
	var out [][]string
	for len(s) > n {
		out = append(out, s[:n])
		s = s[n:]
	}
	if len(s) > 0 {
		out = append(out, s)
	}
	return out
}
