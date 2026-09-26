package poll

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// Tests for the 32-bit ifTable fallback (SNMPv1 / no HC counters) and the wireless RF reads.

// fakeAgent answers GETs from a fixed table. As a v1 agent it fails the whole PDU with
// noSuchName when any OID is missing, like the real thing; as v2c it answers noSuchInstance per
// varbind. Every request is kept so a test can see what was asked for.
type fakeAgent struct {
	vals  map[string]uint64
	v1    bool
	calls [][]string
}

func (a *fakeAgent) get(oids []string) (*gosnmp.SnmpPacket, error) {
	a.calls = append(a.calls, oids)
	res := &gosnmp.SnmpPacket{}
	for _, oid := range oids {
		v, ok := a.vals[strings.TrimPrefix(oid, ".")]
		if !ok {
			if a.v1 {
				return &gosnmp.SnmpPacket{Error: gosnmp.NoSuchName}, nil
			}
			res.Variables = append(res.Variables, gosnmp.SnmpPDU{Name: "." + oid, Type: gosnmp.NoSuchInstance})
			continue
		}
		res.Variables = append(res.Variables, gosnmp.SnmpPDU{Name: "." + oid, Type: gosnmp.Counter32, Value: uint(v)})
	}
	return res, nil
}

func (a *fakeAgent) asked(prefix string) bool {
	for _, c := range a.calls {
		for _, o := range c {
			if strings.HasPrefix(o, prefix) {
				return true
			}
		}
	}
	return false
}

// the port stats target the server sends (DispatchAgentJobs::portStatsOids)
func serverPortStats() *proto.PortStatsTarget {
	return &proto.PortStatsTarget{
		Columns: map[string][]string{
			"errors_in": {".1.3.6.1.2.1.2.2.1.14"},
			"pkts_in":   {".1.3.6.1.2.1.31.1.1.1.7", ".1.3.6.1.2.1.31.1.1.1.8", ".1.3.6.1.2.1.31.1.1.1.9"},
			"pkts_out":  {".1.3.6.1.2.1.31.1.1.1.11", ".1.3.6.1.2.1.31.1.1.1.12", ".1.3.6.1.2.1.31.1.1.1.13"},
		},
		Counter32: []string{"errors_in"},
		Fallback: map[string][]string{
			"pkts_in":  {".1.3.6.1.2.1.2.2.1.11", ".1.3.6.1.2.1.2.2.1.12"},
			"pkts_out": {".1.3.6.1.2.1.2.2.1.17", ".1.3.6.1.2.1.2.2.1.18"},
		},
	}
}

func TestV1DeviceGetsOctetsAndPacketsFromTheIfTable(t *testing.T) {
	p := New()
	target := proto.SNMPTarget{Interfaces: []proto.IfaceTarget{{InterfaceID: 5, IfIndex: 2}}, PortStats: serverPortStats()}
	t0 := time.Unix(1_700_000_000, 0)

	tick := func(octets, ucastIn, nucastIn, ucastOut, nucastOut uint64, at time.Time) []proto.FlowResult {
		a := &fakeAgent{v1: true, vals: map[string]uint64{
			"1.3.6.1.2.1.2.2.1.10.2": octets, "1.3.6.1.2.1.2.2.1.16.2": octets, "1.3.6.1.2.1.2.2.1.8.2": 1,
			"1.3.6.1.2.1.2.2.1.14.2": 0,
			"1.3.6.1.2.1.2.2.1.11.2": ucastIn, "1.3.6.1.2.1.2.2.1.12.2": nucastIn,
			"1.3.6.1.2.1.2.2.1.17.2": ucastOut, "1.3.6.1.2.1.2.2.1.18.2": nucastOut,
		}}
		counters := readOctets(a.get, target.Interfaces, true)
		vals := readPortVals(a.get, target.PortStats, target.Interfaces, true)
		if a.asked("1.3.6.1.2.1.31.") {
			t.Fatalf("a v1 device must never be asked for an ifXTable OID: %v", a.calls)
		}
		return p.snmpFlows(target, counters, vals, portCounter32(target.PortStats), at)
	}

	if f := tick(1000, 100, 10, 50, 5, t0); len(f) != 0 {
		t.Fatalf("first tick has nothing to diff against, got %+v", f)
	}
	f := tick(1000+2500, 100+550, 10+50, 50+300, 5+0, t0.Add(10*time.Second))
	if len(f) != 1 {
		t.Fatalf("want one flow, got %+v", f)
	}
	if f[0].InBps != 2000 {
		t.Errorf("in bps = %v, want 2000 from the 32-bit octets", f[0].InBps)
	}
	if f[0].PktsIn == nil || *f[0].PktsIn != 60 || f[0].PktsOut == nil || *f[0].PktsOut != 30 {
		t.Errorf("pkts in/out = %v / %v, want 60 / 30", f[0].PktsIn, f[0].PktsOut)
	}
	if f[0].OperUp == nil || !*f[0].OperUp {
		t.Error("oper status should still come through on v1")
	}
}

func TestHCLessPortFallsBackOnV2c(t *testing.T) {
	ifaces := []proto.IfaceTarget{{InterfaceID: 1, IfIndex: 1}, {InterfaceID: 2, IfIndex: 2}}
	a := &fakeAgent{vals: map[string]uint64{
		// port 1 has the HC counters
		"1.3.6.1.2.1.31.1.1.1.6.1": 10, "1.3.6.1.2.1.31.1.1.1.10.1": 20,
		"1.3.6.1.2.1.31.1.1.1.7.1": 1000, "1.3.6.1.2.1.31.1.1.1.11.1": 900,
		// port 2 only has the ifTable ones
		"1.3.6.1.2.1.2.2.1.10.2": 30, "1.3.6.1.2.1.2.2.1.16.2": 40,
		"1.3.6.1.2.1.2.2.1.11.2": 70, "1.3.6.1.2.1.2.2.1.12.2": 7,
		"1.3.6.1.2.1.2.2.1.17.2": 60,
	}}

	counters := readOctets(a.get, ifaces, false)
	if in, out, is32, ok := octets(counters, "1"); !ok || is32 || in != 10 || out != 20 {
		t.Errorf("port 1 octets = %d %d %v %v, want the HC pair", in, out, is32, ok)
	}
	if in, out, is32, ok := octets(counters, "2"); !ok || !is32 || in != 30 || out != 40 {
		t.Errorf("port 2 octets = %d %d %v %v, want the 32-bit pair", in, out, is32, ok)
	}
	for _, c := range a.calls[1:] {
		for _, o := range c {
			if strings.HasSuffix(o, ".1") {
				t.Errorf("the fallback GET should only ask for port 2, asked %s", o)
			}
		}
	}

	ps := serverPortStats()
	vals := readPortVals(a.get, ps, ifaces, false)
	p1 := portCounters(ps, vals, "1")
	if p1["pkts_in"] != 1000 || p1["pkts_out"] != 900 || p1["pkts_in32"] != 0 {
		t.Errorf("port 1 = %v, want the HC packets", p1)
	}
	p2 := portCounters(ps, vals, "2")
	if p2["pkts_in32"] != 77 || p2["pkts_out32"] != 60 {
		t.Errorf("port 2 = %v, want ifTable packets under the 32 names", p2)
	}
	if _, ok := p2["pkts_in"]; ok {
		t.Errorf("port 2 should have no 64-bit packets: %v", p2)
	}
}

func TestNarrowPacketsAndOctetsWrap(t *testing.T) {
	// the sum of two Counter32s is kept inside 32 bits, so a wrap of one of them is one wrap
	ps := serverPortStats()
	vals := map[string]uint64{"1.3.6.1.2.1.2.2.1.11.1": 4294967000, "1.3.6.1.2.1.2.2.1.12.1": 1000}
	c := portCounters(ps, vals, "1")
	if c["pkts_in32"] != (4294967000+1000)&counter32Max {
		t.Fatalf("pkts_in32 = %d, want the sum inside 32 bits", c["pkts_in32"])
	}

	s := newState()
	t0 := time.Unix(1_700_000_000, 0)
	c32 := portCounter32(ps)
	s.portRates(1, c, c32, t0)
	// unicast wrapped past 2^32 (+796), non-unicast +200: 996 packets in 10s
	next := portCounters(ps, map[string]uint64{"1.3.6.1.2.1.2.2.1.11.1": 500, "1.3.6.1.2.1.2.2.1.12.1": 1200}, "1")
	r := s.portRates(1, next, c32, t0.Add(10*time.Second))
	if r["pkts_in32"] == nil || *r["pkts_in32"] != 99.6 {
		t.Errorf("pkts across the wrap = %v, want 99.6/s", r["pkts_in32"])
	}

	// the 32-bit octets wrap too
	if in, _ := s.rate(9, 4294967000, 0, true, t0); in != nil {
		t.Fatal("first read has no rate")
	}
	in, out := s.rate(9, 500, 125, true, t0.Add(time.Second))
	if in == nil || *in != 796*8 || out == nil || *out != 1000 {
		t.Errorf("32-bit octets across the wrap = %v / %v", in, out)
	}
	// switching over to the HC counters starts over instead of diffing the two
	if in, _ := s.rate(9, 9_000_000_000, 9_000_000_000, false, t0.Add(2*time.Second)); in != nil {
		t.Errorf("a width change should give no rate, got %v", *in)
	}
	// and a big 32-bit drop is still a reboot, not a wrap
	if in, _ := s.rate(10, 3_000_000, 3_000_000, true, t0); in != nil {
		t.Fatal("first read has no rate")
	}
	if in, _ := s.rate(10, 5, 5, true, t0.Add(time.Second)); in != nil {
		t.Errorf("a reset should give no rate, got %v", *in)
	}
}

func TestV1WithAnOlderServerStillSkipsCounter64(t *testing.T) {
	// no Fallback from the server: v1 just gets no packets, and no HC OIDs in the PDU
	ps := &proto.PortStatsTarget{Columns: map[string][]string{"pkts_in": {".1.3.6.1.2.1.31.1.1.1.7"}, "errors_in": {".1.3.6.1.2.1.2.2.1.14"}}}
	got := portStatOids(ps, []proto.IfaceTarget{{InterfaceID: 1, IfIndex: 4}}, true)
	if len(got) != 1 || got[0] != "1.3.6.1.2.1.2.2.1.14.4" {
		t.Errorf("oids = %v", got)
	}
}

// --- wireless -------------------------------------------------------------

func fakeWalk(tables map[string][]gosnmp.SnmpPDU) func(string) ([]gosnmp.SnmpPDU, error) {
	return func(oid string) ([]gosnmp.SnmpPDU, error) {
		if oid == "fail" {
			return nil, errors.New("timeout")
		}
		return tables[oid], nil
	}
}

func intPDU(name string, v int) gosnmp.SnmpPDU {
	return gosnmp.SnmpPDU{Name: name, Type: gosnmp.Integer, Value: v}
}

func TestSNMPWirelessAirOSAPAndStation(t *testing.T) {
	m := &proto.MetricsTarget{
		SignalWalk:       []string{".1.3.6.1.4.1.41112.1.4.7.1.3", ".1.3.6.1.4.1.41112.1.4.5.1.5"},
		CcqWalk:          []string{".1.3.6.1.4.1.41112.1.4.7.1.6", ".1.3.6.1.4.1.41112.1.4.5.1.7"},
		ClientsValueWalk: []string{".1.3.6.1.4.1.41112.1.4.5.1.15"},
	}
	walk := fakeWalk(map[string][]gosnmp.SnmpPDU{
		// AP: three stations
		".1.3.6.1.4.1.41112.1.4.7.1.3":  {intPDU(".1.3.6.1.4.1.41112.1.4.7.1.3.1.1", -60), intPDU(".1.3.6.1.4.1.41112.1.4.7.1.3.1.2", -65), intPDU(".1.3.6.1.4.1.41112.1.4.7.1.3.1.3", -71)},
		".1.3.6.1.4.1.41112.1.4.7.1.6":  {intPDU(".1.3.6.1.4.1.41112.1.4.7.1.6.1.1", 98), intPDU(".1.3.6.1.4.1.41112.1.4.7.1.6.1.2", 91)},
		".1.3.6.1.4.1.41112.1.4.5.1.15": {intPDU(".1.3.6.1.4.1.41112.1.4.5.1.15.1", 3)},
	})
	get := func([]string) (*gosnmp.SnmpPacket, error) { return nil, errors.New("not used") }

	w := snmpWireless(get, walk, m)
	if w.SignalDbm == nil || *w.SignalDbm != -65.3 {
		t.Errorf("signal = %v, want -65.3", w.SignalDbm)
	}
	if w.CcqPct == nil || *w.CcqPct != 94.5 {
		t.Errorf("ccq = %v, want 94.5", w.CcqPct)
	}
	if w.SnrDb != nil {
		t.Errorf("airMAX has no SNR, got %v", *w.SnrDb)
	}
	if w.Clients == nil || *w.Clients != 3 {
		t.Errorf("clients = %v, want the reported 3", w.Clients)
	}
}

func TestSNMPWirelessScalarsAndRowCounts(t *testing.T) {
	// Cambium SM style scalars, MikroTik style registration-table row count
	m := &proto.MetricsTarget{
		SignalOids:  []string{".1.3.6.1.4.1.17713.21.1.2.3.0"},
		SnrOids:     []string{".1.3.6.1.4.1.17713.21.1.2.18.0", ".9.9.9.0"},
		CcqOids:     []string{".8.8.8.0"},
		ClientsWalk: []string{".1.3.6.1.4.1.14988.1.1.1.2.1.3"},
	}
	get := func(oids []string) (*gosnmp.SnmpPacket, error) {
		switch oids[0] {
		case ".1.3.6.1.4.1.17713.21.1.2.3.0":
			return &gosnmp.SnmpPacket{Variables: []gosnmp.SnmpPDU{intPDU(oids[0], -58)}}, nil
		case ".1.3.6.1.4.1.17713.21.1.2.18.0":
			return &gosnmp.SnmpPacket{Variables: []gosnmp.SnmpPDU{{Name: oids[0], Type: gosnmp.OctetString, Value: []byte("31")}}}, nil
		case ".9.9.9.0":
			return &gosnmp.SnmpPacket{Error: gosnmp.NoSuchName}, nil // v1 style absent
		}
		return nil, errors.New("timeout")
	}
	walk := fakeWalk(map[string][]gosnmp.SnmpPDU{
		".1.3.6.1.4.1.14988.1.1.1.2.1.3": {intPDU(".1.3.6.1.4.1.14988.1.1.1.2.1.3.1", -50), intPDU(".1.3.6.1.4.1.14988.1.1.1.2.1.3.2", -70)},
	})

	w := snmpWireless(get, walk, m)
	if w.SignalDbm == nil || *w.SignalDbm != -58 || w.SnrDb == nil || *w.SnrDb != 31 {
		t.Errorf("signal / snr = %v / %v", w.SignalDbm, w.SnrDb)
	}
	if w.CcqPct != nil {
		t.Errorf("a GET that times out should leave ccq nil, got %v", *w.CcqPct)
	}
	if w.Clients == nil || *w.Clients != 2 {
		t.Errorf("clients = %v, want the 2 rows counted", w.Clients)
	}
}

func TestSNMPWirelessMissingEverythingIsEmpty(t *testing.T) {
	m := &proto.MetricsTarget{SignalWalk: []string{"fail"}, ClientsWalk: []string{".1.2.3"}, ClientsValueWalk: []string{".4.5.6"}}
	w := snmpWireless(func([]string) (*gosnmp.SnmpPacket, error) { return nil, errors.New("x") }, fakeWalk(nil), m)
	if !w.Empty() {
		t.Errorf("nothing answered, want empty, got %+v", w)
	}
	// and a profile with no RF at all reads nothing
	if w := snmpWireless(nil, nil, &proto.MetricsTarget{}); !w.Empty() {
		t.Errorf("no RF oids should read nothing, got %+v", w)
	}
}

func TestRouterOSWirelessRegistrationTable(t *testing.T) {
	w := routerOSWireless([]map[string]string{
		{"signal-strength": "-65dBm@6Mbps", "signal-to-noise": "40", "tx-ccq": "90"},
		{"signal-strength": "-70", "signal-to-noise": "35", "tx-ccq": "150"},
		{"interface": "wlan1"},
	})
	if w.Clients == nil || *w.Clients != 3 {
		t.Errorf("clients = %v, want 3 rows", w.Clients)
	}
	if w.SignalDbm == nil || *w.SignalDbm != -67.5 || w.SnrDb == nil || *w.SnrDb != 37.5 {
		t.Errorf("signal / snr = %v / %v", w.SignalDbm, w.SnrDb)
	}
	if w.CcqPct == nil || *w.CcqPct != 100 {
		t.Errorf("ccq = %v, want the average clamped to 100", w.CcqPct)
	}
	if !routerOSWireless(nil).Empty() {
		t.Error("no rows should be no RF at all")
	}
}

func TestMetricsResultAlwaysSendsTheWirelessKeys(t *testing.T) {
	b, _ := json.Marshal(proto.MetricsResult{DeviceID: 1})
	for _, k := range []string{`"signal_dbm":null`, `"snr_db":null`, `"ccq_pct":null`, `"wireless_clients":null`} {
		if !strings.Contains(string(b), k) {
			t.Errorf("want %s in %s", k, b)
		}
	}
	sig, n := -61.0, 4
	m := proto.MetricsResult{DeviceID: 1}
	m.SetWireless(proto.Wireless{SignalDbm: &sig, Clients: &n})
	b, _ = json.Marshal(m)
	if !strings.Contains(string(b), `"signal_dbm":-61`) || !strings.Contains(string(b), `"wireless_clients":4`) {
		t.Errorf("rf not marshalled: %s", b)
	}
}
