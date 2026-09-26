package poll

import (
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// ifXTable 64-bit HC counters (same OIDs the central SNMP driver uses).
const (
	oidInOctets  = "1.3.6.1.2.1.31.1.1.1.6."  // ifHCInOctets.<ifIndex>
	oidOutOctets = "1.3.6.1.2.1.31.1.1.1.10." // ifHCOutOctets.<ifIndex>
	// ifOperStatus.<ifIndex>, 1 = up. Read every tick like the central SNMP driver does.
	oidOperStatus = "1.3.6.1.2.1.2.2.1.8."
	// 32-bit ifTable octets, for SNMPv1 (no Counter64 at all, eg airOS) and any port whose HC
	// counters don't answer. They wrap, see counterDelta.
	oidInOctets32  = "1.3.6.1.2.1.2.2.1.10." // ifInOctets.<ifIndex>
	oidOutOctets32 = "1.3.6.1.2.1.2.2.1.16." // ifOutOctets.<ifIndex>
)

// narrowSuffix marks a port counter that came from its 32-bit fallback columns (pkts_in32), so
// the counter state never diffs a 64-bit read against a 32-bit one.
const narrowSuffix = "32"

// dialSNMP builds and connects a gosnmp handle for a host, honouring v1/v2c (community) or
// v3 (USM) exactly like the central PhpSnmpClient. The caller closes g.Conn.
func dialSNMP(ip, community string, auth proto.SNMPAuth) (*gosnmp.GoSNMP, error) {
	g := &gosnmp.GoSNMP{
		Target:  ip,
		Port:    161,
		Timeout: 2 * time.Second,
		Retries: 1,
		MaxOids: 60,
	}
	switch auth.Version {
	case "3":
		g.Version = gosnmp.Version3
		g.SecurityModel = gosnmp.UserSecurityModel
		g.MsgFlags = msgFlags(auth.SecLevel)
		usm := &gosnmp.UsmSecurityParameters{UserName: auth.SecName}
		if auth.SecLevel == "authNoPriv" || auth.SecLevel == "authPriv" {
			usm.AuthenticationProtocol = authProto(auth.AuthProtocol)
			usm.AuthenticationPassphrase = auth.AuthPassphrase
		}
		if auth.SecLevel == "authPriv" {
			usm.PrivacyProtocol = privProto(auth.PrivProtocol)
			usm.PrivacyPassphrase = auth.PrivPassphrase
		}
		g.SecurityParameters = usm
	case "1":
		g.Version = gosnmp.Version1
		g.Community = community
	default:
		g.Version = gosnmp.Version2c
		g.Community = community
	}
	if err := g.Connect(); err != nil {
		return nil, err
	}
	return g, nil
}

// msgFlags maps our security level to gosnmp's PDU flags (defaults to AuthPriv).
func msgFlags(level string) gosnmp.SnmpV3MsgFlags {
	switch level {
	case "noAuthNoPriv":
		return gosnmp.NoAuthNoPriv
	case "authNoPriv":
		return gosnmp.AuthNoPriv
	default:
		return gosnmp.AuthPriv
	}
}

// authProto / privProto map the server's protocol names to gosnmp constants.
func authProto(name string) gosnmp.SnmpV3AuthProtocol {
	switch strings.ToUpper(strings.ReplaceAll(name, "-", "")) {
	case "MD5":
		return gosnmp.MD5
	case "SHA224":
		return gosnmp.SHA224
	case "SHA256":
		return gosnmp.SHA256
	case "SHA384":
		return gosnmp.SHA384
	case "SHA512":
		return gosnmp.SHA512
	default:
		return gosnmp.SHA
	}
}

func privProto(name string) gosnmp.SnmpV3PrivProtocol {
	switch strings.ToUpper(strings.ReplaceAll(name, "-", "")) {
	case "DES":
		return gosnmp.DES
	case "AES192":
		return gosnmp.AES192
	case "AES256":
		return gosnmp.AES256
	default:
		return gosnmp.AES
	}
}

// pollSNMP reads each interface's HC octet counters over SNMP (v1/v2c/v3) and turns consecutive
// samples into bits/sec. Interfaces with no prior sample (or a counter reset) yield no rate
// this tick - exactly like the central path's first poll.
//
// ifOperStatus rides along in the same GETs every tick. When the server asks for port stats
// (t.PortStats, on its slower cadence) the error / discard / packet columns are read as well, in
// their own GETs so an OID the box doesn't have can't cost us the octets, and turned into rates.
func (p *Poller) pollSNMP(t proto.SNMPTarget) []proto.FlowResult {
	if len(t.Interfaces) == 0 {
		return nil
	}

	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		return nil
	}
	defer g.Conn.Close()

	v1 := g.Version == gosnmp.Version1
	counters := readOctets(g.Get, t.Interfaces, v1)

	var portVals map[string]uint64
	if t.PortStats != nil {
		portVals = readPortVals(g.Get, t.PortStats, t.Interfaces, v1)
	}

	return p.snmpFlows(t, counters, portVals, portCounter32(t.PortStats), time.Now())
}

// readOctets GETs the octet counters and ifOperStatus for every interface. v1 is asked for the
// 32-bit ifTable octets straight away (a Counter64 would fail its whole PDU); anything else gets
// the HC ones, and then a second GET of the 32-bit ones for just the ports the HC read missed.
func readOctets(get func([]string) (*gosnmp.SnmpPacket, error), ifaces []proto.IfaceTarget, v1 bool) map[string]uint64 {
	in, out := oidInOctets, oidOutOctets
	if v1 {
		in, out = oidInOctets32, oidOutOctets32
	}
	oids := make([]string, 0, len(ifaces)*3)
	for _, i := range ifaces {
		idx := strconv.Itoa(i.IfIndex)
		oids = append(oids, in+idx, out+idx, oidOperStatus+idx)
	}
	vals := getValues(get, oids, 60)
	if v1 {
		return vals
	}

	var narrow []string
	for _, i := range ifaces {
		idx := strconv.Itoa(i.IfIndex)
		_, okIn := vals[oidInOctets+idx]
		_, okOut := vals[oidOutOctets+idx]
		if !okIn || !okOut {
			narrow = append(narrow, oidInOctets32+idx, oidOutOctets32+idx)
		}
	}
	if len(narrow) > 0 {
		for k, v := range getValues(get, narrow, 60) {
			vals[k] = v
		}
	}
	return vals
}

// octets picks one port's in/out octets out of a readOctets result: the HC pair when both are
// there, else the 32-bit pair (is32).
func octets(counters map[string]uint64, idx string) (in, out uint64, is32, ok bool) {
	in, okIn := counters[oidInOctets+idx]
	out, okOut := counters[oidOutOctets+idx]
	if okIn && okOut {
		return in, out, false, true
	}
	in, okIn = counters[oidInOctets32+idx]
	out, okOut = counters[oidOutOctets32+idx]
	return in, out, true, okIn && okOut
}

// snmpFlows turns one tick's GET results into flows (split out so it can be tested without a
// device).
func (p *Poller) snmpFlows(t proto.SNMPTarget, counters, portVals map[string]uint64, counter32 map[string]bool, now time.Time) []proto.FlowResult {
	flows := make([]proto.FlowResult, 0, len(t.Interfaces))
	for _, i := range t.Interfaces {
		idx := strconv.Itoa(i.IfIndex)
		// port rates first: the counter state has to move on even on a tick with no bps yet
		var rates map[string]*float64
		if t.PortStats != nil {
			if c := portCounters(t.PortStats, portVals, idx); len(c) > 0 {
				rates = p.state.portRates(i.InterfaceID, c, counter32, now)
			}
		}

		in, out, is32, ok := octets(counters, idx)
		if !ok {
			continue
		}
		inBps, outBps := p.state.rate(i.InterfaceID, in, out, is32, now)
		if inBps == nil {
			continue // first sample or reset - no rate yet
		}
		f := proto.FlowResult{InterfaceID: i.InterfaceID, InBps: *inBps, OutBps: *outBps}
		if st, ok := counters[oidOperStatus+idx]; ok {
			up := st == 1 // 1=up, anything else (down/testing/dormant/...) is not up
			f.OperUp = &up
		}
		for name, r := range rates {
			f.SetPortRate(strings.TrimSuffix(name, narrowSuffix), r)
		}
		flows = append(flows, f)
	}
	return flows
}

// getValues GETs oids in chunks of n and returns the numeric value of every varbind that came
// back with one. An absent OID (noSuchObject/noSuchInstance, or a v1 noSuchName error on the
// chunk) just isn't in the map, and a failed chunk doesn't stop the rest.
func getValues(get func([]string) (*gosnmp.SnmpPacket, error), oids []string, n int) map[string]uint64 {
	vals := map[string]uint64{}
	for _, c := range chunk(oids, n) {
		res, err := get(c)
		if err != nil || res == nil {
			continue // a black-holing device shouldn't sink the whole batch
		}
		if res.Error != gosnmp.NoError {
			continue // v1 fails the whole PDU for one unknown OID
		}
		for _, v := range res.Variables {
			switch v.Type {
			case gosnmp.Null, gosnmp.NoSuchObject, gosnmp.NoSuchInstance, gosnmp.EndOfMibView:
				continue
			}
			vals[normalise(v.Name)] = gosnmp.ToBigInt(v.Value).Uint64()
		}
	}
	return vals
}

// portStatOids is every column OID for every interface. On v1 a counter with a fallback is
// asked for by its 32-bit columns instead, and any other ifXTable (Counter64) column is left
// out: v1 can't carry them and one would fail the whole PDU.
func portStatOids(ps *proto.PortStatsTarget, ifaces []proto.IfaceTarget, v1 bool) []string {
	var out []string
	for _, i := range ifaces {
		idx := strconv.Itoa(i.IfIndex)
		for name, cols := range ps.Columns {
			if fb, ok := ps.Fallback[name]; v1 && ok && len(fb) > 0 {
				cols = fb
			}
			for _, col := range cols {
				col = strings.TrimPrefix(col, ".")
				if v1 && strings.HasPrefix(col, "1.3.6.1.2.1.31.") {
					continue
				}
				out = append(out, col+"."+idx)
			}
		}
	}
	return out
}

// readPortVals GETs the port counter columns (see portStatOids), then on v2c/v3 a second GET of
// the fallback columns for just the ports whose primary columns didn't answer, so a box without
// HC packet counters still gets packets from the 32-bit ifTable.
func readPortVals(get func([]string) (*gosnmp.SnmpPacket, error), ps *proto.PortStatsTarget, ifaces []proto.IfaceTarget, v1 bool) map[string]uint64 {
	vals := getValues(get, portStatOids(ps, ifaces, v1), 60)
	if v1 || len(ps.Fallback) == 0 {
		return vals
	}
	var more []string
	for _, i := range ifaces {
		idx := strconv.Itoa(i.IfIndex)
		for name, fb := range ps.Fallback {
			cols := ps.Columns[name]
			if len(fb) == 0 || (len(cols) > 0 && hasCol(vals, cols[0], idx)) {
				continue
			}
			for _, col := range fb {
				more = append(more, strings.TrimPrefix(col, ".")+"."+idx)
			}
		}
	}
	if len(more) > 0 {
		for k, v := range getValues(get, more, 60) {
			vals[k] = v
		}
	}
	return vals
}

func hasCol(vals map[string]uint64, col, idx string) bool {
	_, ok := vals[strings.TrimPrefix(col, ".")+"."+idx]
	return ok
}

// portCounters adds up each rate's columns for one ifIndex. The first column has to be there
// (unicast for packets), otherwise that counter is left out rather than half counted. A counter
// whose own columns didn't answer comes from its fallback columns when those did, kept inside
// 32 bits (so a wrap of either column still reads as one Counter32 wrap) and named with
// narrowSuffix.
func portCounters(ps *proto.PortStatsTarget, vals map[string]uint64, idx string) map[string]uint64 {
	out := map[string]uint64{}
	for name, cols := range ps.Columns {
		if sum, ok := sumCols(cols, vals, idx); ok {
			out[name] = sum
		} else if sum, ok := sumCols(ps.Fallback[name], vals, idx); ok {
			out[name+narrowSuffix] = sum & counter32Max
		}
	}
	return out
}

func sumCols(cols []string, vals map[string]uint64, idx string) (uint64, bool) {
	if len(cols) == 0 {
		return 0, false
	}
	first, ok := vals[strings.TrimPrefix(cols[0], ".")+"."+idx]
	if !ok {
		return 0, false
	}
	sum := first
	for _, col := range cols[1:] {
		sum += vals[strings.TrimPrefix(col, ".")+"."+idx]
	}
	return sum, true
}

// portCounter32 is the set of port counter names that can wrap: the server's Counter32 list plus
// every fallback (32-bit) name.
func portCounter32(ps *proto.PortStatsTarget) map[string]bool {
	out := map[string]bool{}
	if ps == nil {
		return out
	}
	for _, n := range ps.Counter32 {
		out[n] = true
	}
	for n := range ps.Fallback {
		out[n+narrowSuffix] = true
	}
	return out
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
