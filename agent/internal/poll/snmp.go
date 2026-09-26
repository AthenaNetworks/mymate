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
)

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

	oids := make([]string, 0, len(t.Interfaces)*3)
	for _, i := range t.Interfaces {
		idx := strconv.Itoa(i.IfIndex)
		oids = append(oids, oidInOctets+idx, oidOutOctets+idx, oidOperStatus+idx)
	}
	counters := getValues(g.Get, oids, 60)

	var portVals map[string]uint64
	counter32 := map[string]bool{}
	if t.PortStats != nil {
		portVals = getValues(g.Get, portStatOids(t.PortStats, t.Interfaces, g.Version == gosnmp.Version1), 60)
		for _, n := range t.PortStats.Counter32 {
			counter32[n] = true
		}
	}

	return p.snmpFlows(t, counters, portVals, counter32, time.Now())
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
			if c := portCounters(t.PortStats.Columns, portVals, idx); len(c) > 0 {
				rates = p.state.portRates(i.InterfaceID, c, counter32, now)
			}
		}

		in, okIn := counters[oidInOctets+idx]
		out, okOut := counters[oidOutOctets+idx]
		if !okIn || !okOut {
			continue
		}
		inBps, outBps := p.state.rate(i.InterfaceID, in, out, now)
		if inBps == nil {
			continue // first sample or reset - no rate yet
		}
		f := proto.FlowResult{InterfaceID: i.InterfaceID, InBps: *inBps, OutBps: *outBps}
		if st, ok := counters[oidOperStatus+idx]; ok {
			up := st == 1 // 1=up, anything else (down/testing/dormant/...) is not up
			f.OperUp = &up
		}
		for name, r := range rates {
			f.SetPortRate(name, r)
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

// portStatOids is every column OID for every interface. On v1 the ifXTable (Counter64) ones are
// left out, v1 can't carry them and one would fail the whole PDU.
func portStatOids(ps *proto.PortStatsTarget, ifaces []proto.IfaceTarget, v1 bool) []string {
	var out []string
	for _, i := range ifaces {
		idx := strconv.Itoa(i.IfIndex)
		for _, cols := range ps.Columns {
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

// portCounters adds up each rate's columns for one ifIndex. The first column has to be there
// (unicast for packets), otherwise that counter is left out rather than half counted.
func portCounters(columns map[string][]string, vals map[string]uint64, idx string) map[string]uint64 {
	out := map[string]uint64{}
	for name, cols := range columns {
		if len(cols) == 0 {
			continue
		}
		first, ok := vals[strings.TrimPrefix(cols[0], ".")+"."+idx]
		if !ok {
			continue
		}
		sum := first
		for _, col := range cols[1:] {
			sum += vals[strings.TrimPrefix(col, ".")+"."+idx]
		}
		out[name] = sum
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
