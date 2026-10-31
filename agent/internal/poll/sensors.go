package poll

import (
	"errors"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// pollSNMPSensors reads the custom sensors the server sent for this device (#51). Values go back
// raw; the server reduces and scales them the same way as its own sensor poll.
func (p *Poller) pollSNMPSensors(t proto.SNMPTarget) []proto.SensorResult {
	if len(t.Sensors) == 0 {
		return nil
	}
	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		return nil
	}
	defer g.Conn.Close()

	var out []proto.SensorResult
	for _, s := range t.Sensors {
		vals, err := readSensor(g, s.OID, s.Mode)
		if err != nil || len(vals) == 0 {
			continue // unreachable / unsupported OID - skip this reading, like the central poll
		}
		out = append(out, proto.SensorResult{DeviceID: t.DeviceID, SensorID: s.SensorID, Values: vals})
	}
	return out
}

// TestSNMP runs the sensor form's "Test OID" from the agent's network (#51).
func (p *Poller) TestSNMP(t proto.SNMPTest) proto.SNMPTestResult {
	res := proto.SNMPTestResult{Type: "snmp_test_result", RequestID: t.RequestID}
	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		res.Error = err.Error()
		return res
	}
	defer g.Conn.Close()

	vals, err := readSensor(g, t.OID, t.Mode)
	if err != nil {
		res.Error = err.Error()
		return res
	}
	res.Values = vals
	return res
}

// readSensor GETs a scalar OID or walks a table and returns every value as a string.
func readSensor(g *gosnmp.GoSNMP, oid, mode string) ([]string, error) {
	if oid == "" {
		return nil, errors.New("no OID")
	}
	var pdus []gosnmp.SnmpPDU
	if mode == "walk" {
		var err error
		if g.Version == gosnmp.Version1 {
			pdus, err = g.WalkAll(oid)
		} else {
			pdus, err = g.BulkWalkAll(oid)
		}
		if err != nil {
			return nil, err
		}
	} else {
		pkt, err := g.Get([]string{oid})
		if err != nil {
			return nil, err
		}
		pdus = pkt.Variables
	}

	vals := []string{}
	for _, pdu := range pdus {
		if v, ok := pduValue(pdu); ok {
			vals = append(vals, v)
		}
	}
	return vals, nil
}

// pduValue renders a PDU the way walkRaw does: strings as-is, OIDs dotted, numbers in decimal.
// Absent values (noSuchObject etc.) are dropped.
func pduValue(pdu gosnmp.SnmpPDU) (string, bool) {
	switch pdu.Type {
	case gosnmp.Null, gosnmp.NoSuchObject, gosnmp.NoSuchInstance, gosnmp.EndOfMibView:
		return "", false
	case gosnmp.OctetString:
		return pduString(pdu), true
	case gosnmp.ObjectIdentifier:
		s, _ := pdu.Value.(string)
		return s, true
	default:
		return gosnmp.ToBigInt(pdu.Value).String(), true
	}
}
