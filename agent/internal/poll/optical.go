package poll

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/go-routeros/routeros/v3"
	"github.com/gosnmp/gosnmp"
)

// SFP / fibre optical power (#11). Read on the server's optical cadence (it only sets the
// target when due), reported raw per port - if_index and/or name - so the server matches ports
// to interfaces the same way it does for centrally polled devices.

// pollSNMPOptical walks the vendor optical table the server described. Returns nil when there's
// nothing to do or the device couldn't be reached, so the server keeps its last values; a
// successful walk returns an entry even with no ports (modules removed -> server clears them).
func (p *Poller) pollSNMPOptical(t proto.SNMPTarget) *proto.DeviceOptical {
	o := t.Optical
	if o == nil || (o.RxWalk == "" && o.TxWalk == "") {
		return nil
	}
	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		return nil
	}
	defer g.Conn.Close()

	rx, rxErr := walkOptical(g.WalkAll, o.RxWalk)
	tx, txErr := walkOptical(g.WalkAll, o.TxWalk)
	if rxErr != nil && txErr != nil {
		return nil // both walks failed - treat as unreachable, not "no modules"
	}
	names := walkStrings(g, o.NameWalk)

	return &proto.DeviceOptical{DeviceID: t.DeviceID, Ports: opticalPorts(rx, tx, names, o.Divisor)}
}

// walkOptical walks one numeric column keyed by row index. A blank oid is "not configured"
// (empty, no error).
func walkOptical(walk func(string) ([]gosnmp.SnmpPDU, error), oid string) (map[string]float64, error) {
	out := map[string]float64{}
	if oid == "" {
		return out, nil
	}
	pdus, err := walk(oid)
	if err != nil {
		return out, err
	}
	for _, pdu := range pdus {
		if f, ok := pduFloat(pdu); ok {
			out[suffix(oid, pdu.Name)] = f
		}
	}
	return out, nil
}

// opticalPorts merges the rx/tx columns (and optional names) into ports, scaling by divisor and
// dropping rows with no usable reading in either direction.
func opticalPorts(rx, tx map[string]float64, names map[string]string, divisor int) []proto.OpticalPort {
	if divisor < 1 {
		divisor = 1
	}
	idx := map[string]bool{}
	for k := range rx {
		idx[k] = true
	}
	for k := range tx {
		idx[k] = true
	}

	ports := []proto.OpticalPort{}
	for k := range idx {
		var r, t *float64
		if v, ok := rx[k]; ok {
			r = validDbm(v / float64(divisor))
		}
		if v, ok := tx[k]; ok {
			t = validDbm(v / float64(divisor))
		}
		if r == nil && t == nil {
			continue
		}
		port := proto.OpticalPort{Name: strings.Trim(strings.TrimSpace(names[k]), "\""), RxDbm: r, TxDbm: t}
		if n, err := strconv.Atoi(k); err == nil && n > 0 {
			port.IfIndex = n
		}
		ports = append(ports, port)
	}
	return ports
}

// pollRouterOSOptical reads SFP power over the RouterOS API: monitor every ethernet port once and
// keep the rows that carry sfp-rx-power / sfp-tx-power (copper ports and empty cages don't).
// Mirrors the central OpticalPowerReader.
func (p *Poller) pollRouterOSOptical(t proto.RouterOSTarget) *proto.DeviceOptical {
	if !t.Optical {
		return nil
	}
	port := t.APIPort
	if port == 0 {
		port = 8728
	}
	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", t.IP, port), t.Username, t.Password, 3*time.Second)
	if err != nil {
		return nil
	}
	defer c.Close()

	eth, err := c.Run("/interface/ethernet/print", "=.proplist=name")
	if err != nil {
		return nil
	}
	var names []string
	for _, re := range eth.Re {
		if n := re.Map["name"]; n != "" {
			names = append(names, n)
		}
	}
	out := &proto.DeviceOptical{DeviceID: t.DeviceID, Ports: []proto.OpticalPort{}}
	if len(names) == 0 {
		return out
	}

	mon, err := c.Run("/interface/ethernet/monitor", "=numbers="+strings.Join(names, ","), "=once=")
	if err != nil {
		return nil
	}
	rows := make([]map[string]string, 0, len(mon.Re))
	for _, re := range mon.Re {
		rows = append(rows, re.Map)
	}
	out.Ports = routerOSOpticalPorts(rows)
	return out
}

// routerOSOpticalPorts pulls the SFP light levels out of ethernet monitor rows.
func routerOSOpticalPorts(rows []map[string]string) []proto.OpticalPort {
	ports := []proto.OpticalPort{}
	for _, row := range rows {
		name := row["name"]
		r := parseDbm(row["sfp-rx-power"])
		t := parseDbm(row["sfp-tx-power"])
		if name == "" || (r == nil && t == nil) {
			continue
		}
		ports = append(ports, proto.OpticalPort{Name: name, RxDbm: r, TxDbm: t})
	}
	return ports
}

var leadingNumber = regexp.MustCompile(`-?\d+(\.\d+)?`)

// parseDbm reads a RouterOS power value ("-5.123", or "-5.123dBm" on RouterOS 6). Empty or
// unparseable -> nil.
func parseDbm(s string) *float64 {
	m := leadingNumber.FindString(strings.TrimSpace(s))
	if m == "" {
		return nil
	}
	f, err := strconv.ParseFloat(m, 64)
	if err != nil {
		return nil
	}
	return validDbm(f)
}

// validDbm keeps a plausible light level. Real optics sit around -40..+10 dBm; values far outside
// are vendor sentinels for "no reading", same bound the server applies.
func validDbm(v float64) *float64 {
	if v < -60 || v > 30 {
		return nil
	}
	return &v
}
