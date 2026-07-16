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
func (p *Poller) pollSNMP(t proto.SNMPTarget) []proto.FlowResult {
	if len(t.Interfaces) == 0 {
		return nil
	}

	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
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
