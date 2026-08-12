package poll

import (
	"sort"
	"strconv"
	"strings"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// Standard IF-MIB / SNMPv2-MIB / ENTITY-MIB OIDs for interface discovery + device facts. These
// are IETF standard MIBs (not vendor-specific), so the agent can hold them; the vendor-specific
// *parsing* of what comes back stays server-side (CaptureDeviceFacts). Fixes #33 for the SNMP
// path: an agent-polled device is discovered and fact-captured from the agent, not the server.
const (
	oidIfDescr      = "1.3.6.1.2.1.2.2.1.2"       // ifDescr.<idx>
	oidIfName       = "1.3.6.1.2.1.31.1.1.1.1"    // ifName.<idx>
	oidIfHighSpeed  = "1.3.6.1.2.1.31.1.1.1.15"   // ifHighSpeed.<idx> (Mbps)
	oidIfOperStatus = "1.3.6.1.2.1.2.2.1.8"       // ifOperStatus.<idx> (1=up)
	oidSysDescr     = "1.3.6.1.2.1.1.1.0"
	oidSysUpTime    = "1.3.6.1.2.1.1.3.0"
	oidSysLocation  = "1.3.6.1.2.1.1.6.0"
	oidHrMemorySize = "1.3.6.1.2.1.25.2.2.0"      // hrMemorySize (KB)
	oidEntModel     = "1.3.6.1.2.1.47.1.1.1.1.13" // entPhysicalModelName
	oidEntSerial    = "1.3.6.1.2.1.47.1.1.1.1.11" // entPhysicalSerialNum
)

// discoverDevice walks an SNMP device's interfaces + facts. Runs only when the server set
// Discover on the target (the discovery cadence). Returns nil if the device can't be reached.
func (p *Poller) discoverDevice(t proto.SNMPTarget) *proto.DeviceDiscovery {
	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		return nil
	}
	defer g.Conn.Close()

	ifaces := walkInterfaces(g)
	facts := walkFacts(g)
	if len(ifaces) == 0 && facts == nil {
		return nil
	}
	return &proto.DeviceDiscovery{DeviceID: t.DeviceID, Interfaces: ifaces, Facts: facts}
}

// walkInterfaces builds one DiscoveredIface per ifTable row (keyed by ifIndex).
func walkInterfaces(g *gosnmp.GoSNMP) []proto.DiscoveredIface {
	names := walkStrings(g, oidIfName)
	descrs := walkStrings(g, oidIfDescr)
	speeds := walkNumbersByIndex(g, oidIfHighSpeed)
	opers := walkNumbersByIndex(g, oidIfOperStatus)

	// Union of indexes that carry at least a name or a description.
	seen := map[string]bool{}
	for k := range names {
		seen[k] = true
	}
	for k := range descrs {
		seen[k] = true
	}

	out := make([]proto.DiscoveredIface, 0, len(seen))
	for idxStr := range seen {
		idx, err := strconv.Atoi(idxStr)
		if err != nil {
			continue
		}
		name := strings.TrimSpace(names[idxStr])
		descr := strings.TrimSpace(descrs[idxStr])
		if name == "" {
			name = descr
		}
		if name == "" {
			continue
		}
		var operUp *bool
		if v, ok := opers[idxStr]; ok {
			up := int(v) == 1 // ifOperStatus: 1=up, everything else = down/unknown
			operUp = &up
		}
		out = append(out, proto.DiscoveredIface{
			IfIndex:   idx,
			Name:      name,
			Descr:     descr,
			SpeedMbps: int(speeds[idxStr]), // ifHighSpeed is already Mbps; 0 when absent
			OperUp:    operUp,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].IfIndex < out[j].IfIndex })
	return out
}

// walkFacts GETs the scalar facts and walks the ENTITY-MIB model/serial columns. Everything is
// returned raw for the server to parse (vendor/model derivation lives there).
func walkFacts(g *gosnmp.GoSNMP) *proto.DeviceFacts {
	f := &proto.DeviceFacts{}

	if res, err := g.Get([]string{oidSysDescr, oidSysLocation, oidSysUpTime, oidHrMemorySize}); err == nil {
		for _, v := range res.Variables {
			switch normalise(v.Name) {
			case oidSysDescr:
				f.SysDescr = strings.TrimSpace(pduString(v))
			case oidSysLocation:
				f.SysLocation = strings.TrimSpace(pduString(v))
			case oidSysUpTime:
				if n := gosnmp.ToBigInt(v.Value); n != nil {
					u := n.Uint64()
					f.UptimeTicks = &u
				}
			case oidHrMemorySize:
				if n := gosnmp.ToBigInt(v.Value); n != nil {
					u := n.Uint64()
					f.MemKb = &u
				}
			}
		}
	}

	f.EntModels = stringsByIndex(walkStrings(g, oidEntModel))
	f.EntSerials = stringsByIndex(walkStrings(g, oidEntSerial))

	if f.SysDescr == "" && f.SysLocation == "" && f.UptimeTicks == nil && f.MemKb == nil && len(f.EntModels) == 0 {
		return nil
	}
	return f
}

// stringsByIndex returns a walk's non-empty values ordered by their numeric row index.
func stringsByIndex(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		a, _ := strconv.Atoi(keys[i])
		b, _ := strconv.Atoi(keys[j])
		return a < b
	})
	out := make([]string, 0, len(keys))
	for _, k := range keys {
		if s := strings.TrimSpace(m[k]); s != "" {
			out = append(out, s)
		}
	}
	return out
}
