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

// Tests for the device page data the agent reports: port rates, oper status, per-CPU load,
// storage and uptime.

func TestCounterDeltaWrapAndReset(t *testing.T) {
	cases := []struct {
		name      string
		last, cur uint64
		is32      bool
		want      uint64
		ok        bool
	}{
		{"normal", 100, 250, false, 150, true},
		{"64-bit going backwards is a reset", 5_000_000, 10, false, 0, false},
		{"counter32 wrap near the top", 4294967000, 500, true, 796, true},
		{"counter32 back to zero is a reboot", 1000, 0, true, 0, false},
		{"counter32 flag but a 64-bit sized value", 1 << 40, 5, true, 0, false},
	}
	for _, c := range cases {
		got, ok := counterDelta(c.last, c.cur, c.is32)
		if ok != c.ok || (ok && got != c.want) {
			t.Errorf("%s: counterDelta(%d, %d) = %d, %v; want %d, %v", c.name, c.last, c.cur, got, ok, c.want, c.ok)
		}
	}
}

func TestPortRatesFirstReadThenRates(t *testing.T) {
	s := newState()
	t0 := time.Unix(1_700_000_000, 0)
	c32 := map[string]bool{"errors_in": true}

	if r := s.portRates(7, map[string]uint64{"errors_in": 4294967000, "pkts_in": 1000}, c32, t0); len(r) != 0 {
		t.Fatalf("first read should have no rates, got %v", r)
	}
	r := s.portRates(7, map[string]uint64{"errors_in": 500, "pkts_in": 7000}, c32, t0.Add(60*time.Second))
	if r["pkts_in"] == nil || *r["pkts_in"] != 100 {
		t.Errorf("pkts_in = %v, want 100/s", r["pkts_in"])
	}
	// 796 errors over the wrap in 60s
	if r["errors_in"] == nil || *r["errors_in"] < 13.26 || *r["errors_in"] > 13.27 {
		t.Errorf("errors_in = %v, want ~13.27/s across the wrap", r["errors_in"])
	}
	// a 64-bit reset gives no rate for that counter only
	r = s.portRates(7, map[string]uint64{"errors_in": 600, "pkts_in": 5}, c32, t0.Add(120*time.Second))
	if r["pkts_in"] != nil {
		t.Errorf("pkts_in after reset = %v, want nil", *r["pkts_in"])
	}
	if r["errors_in"] == nil || *r["errors_in"] != 100.0/60 {
		t.Errorf("errors_in = %v", r["errors_in"])
	}
}

func TestPortCountersSumsColumnsAndNeedsTheFirst(t *testing.T) {
	cols := map[string][]string{
		"pkts_in":   {".1.3.6.1.2.1.31.1.1.1.7", "1.3.6.1.2.1.31.1.1.1.8", "1.3.6.1.2.1.31.1.1.1.9"},
		"pkts_out":  {"1.3.6.1.2.1.31.1.1.1.11", "1.3.6.1.2.1.31.1.1.1.12"},
		"errors_in": {"1.3.6.1.2.1.2.2.1.14"},
	}
	vals := map[string]uint64{
		"1.3.6.1.2.1.31.1.1.1.7.3":  100, // ucast
		"1.3.6.1.2.1.31.1.1.1.9.3":  5,   // bcast (mcast absent)
		"1.3.6.1.2.1.31.1.1.1.12.3": 9,   // out mcast without ucast -> skipped
		"1.3.6.1.2.1.2.2.1.14.3":    2,
	}
	got := portCounters(cols, vals, "3")
	if got["pkts_in"] != 105 || got["errors_in"] != 2 {
		t.Errorf("portCounters = %v", got)
	}
	if _, ok := got["pkts_out"]; ok {
		t.Errorf("pkts_out without its unicast column should be left out: %v", got)
	}
}

func TestPortStatOidsSkipsCounter64OnV1(t *testing.T) {
	ps := &proto.PortStatsTarget{Columns: map[string][]string{
		"pkts_in":   {".1.3.6.1.2.1.31.1.1.1.7"},
		"errors_in": {".1.3.6.1.2.1.2.2.1.14"},
	}}
	ifs := []proto.IfaceTarget{{InterfaceID: 1, IfIndex: 4}}
	if got := portStatOids(ps, ifs, false); len(got) != 2 {
		t.Errorf("v2c oids = %v", got)
	}
	got := portStatOids(ps, ifs, true)
	if len(got) != 1 || got[0] != "1.3.6.1.2.1.2.2.1.14.4" {
		t.Errorf("v1 oids = %v, want only the ifTable one", got)
	}
}

func TestGetValuesSkipsAbsentAndFailedChunks(t *testing.T) {
	get := func(oids []string) (*gosnmp.SnmpPacket, error) {
		if oids[0] == "9.9" {
			return nil, errors.New("timeout")
		}
		if oids[0] == "8.8" {
			return &gosnmp.SnmpPacket{Error: gosnmp.NoSuchName}, nil
		}
		return &gosnmp.SnmpPacket{Variables: []gosnmp.SnmpPDU{
			{Name: ".1.1", Type: gosnmp.Counter64, Value: uint64(42)},
			{Name: ".1.2", Type: gosnmp.NoSuchInstance},
		}}, nil
	}
	got := getValues(get, []string{"1.1", "1.2", "9.9", "8.8"}, 2)
	if len(got) != 1 || got["1.1"] != 42 {
		t.Errorf("getValues = %v", got)
	}
}

func TestSNMPFlowsCarryOperStatusAndPortRates(t *testing.T) {
	p := New()
	target := proto.SNMPTarget{
		Interfaces: []proto.IfaceTarget{{InterfaceID: 11, IfIndex: 2}},
		PortStats: &proto.PortStatsTarget{
			Columns:   map[string][]string{"errors_in": {"1.3.6.1.2.1.2.2.1.14"}, "pkts_in": {"1.3.6.1.2.1.31.1.1.1.7"}},
			Counter32: []string{"errors_in"},
		},
	}
	c32 := map[string]bool{"errors_in": true}
	t0 := time.Unix(1_700_000_000, 0)
	tick := func(octets, errs, pkts uint64, at time.Time) []proto.FlowResult {
		counters := map[string]uint64{oidInOctets + "2": octets, oidOutOctets + "2": octets, oidOperStatus + "2": 2}
		port := map[string]uint64{"1.3.6.1.2.1.2.2.1.14.2": errs, "1.3.6.1.2.1.31.1.1.1.7.2": pkts}
		return p.snmpFlows(target, counters, port, c32, at)
	}

	if f := tick(1000, 10, 100, t0); len(f) != 0 {
		t.Fatalf("first tick should have no flow yet, got %+v", f)
	}
	f := tick(1000+1250, 13, 700, t0.Add(10*time.Second))
	if len(f) != 1 {
		t.Fatalf("want one flow, got %+v", f)
	}
	if f[0].OperUp == nil || *f[0].OperUp {
		t.Errorf("ifOperStatus 2 should be down, got %v", f[0].OperUp)
	}
	if f[0].ErrorsIn == nil || *f[0].ErrorsIn != 0.3 || f[0].PktsIn == nil || *f[0].PktsIn != 60 {
		t.Errorf("rates = errors %v pkts %v", f[0].ErrorsIn, f[0].PktsIn)
	}
	if f[0].InBps != 1000 {
		t.Errorf("in bps = %v, want 1000", f[0].InBps)
	}

	// a tick without port stats (not due) still reports bps + oper, no rates
	target.PortStats = nil
	f = p.snmpFlows(target, map[string]uint64{oidInOctets + "2": 3250, oidOutOctets + "2": 3250, oidOperStatus + "2": 1}, nil, nil, t0.Add(20*time.Second))
	if len(f) != 1 || f[0].ErrorsIn != nil || f[0].OperUp == nil || !*f[0].OperUp {
		t.Errorf("no-port-stats tick = %+v", f)
	}
}

func TestRouterOSFlowsRatesAndDownPorts(t *testing.T) {
	p := New()
	byName := map[string]int{"ether1": 1, "ether2": 2}
	t0 := time.Unix(1_700_000_000, 0)
	print := func(pkts string) []map[string]string {
		return []map[string]string{
			{"name": "ether1", "running": "true", "rx-packet": pkts, "tx-packet": "10", "rx-error": "0", "tx-error": "0", "rx-drop": "1", "tx-drop": "0"},
			{"name": "ether2", "running": "false", "rx-packet": "5"},
		}
	}
	monitor := []map[string]string{{"name": "ether1", "rx-bits-per-second": "8000", "tx-bits-per-second": "4000"}}

	first := p.routerOSFlows(byName, print("1000"), monitor, t0)
	if len(first) != 2 {
		t.Fatalf("want ether1 + the down ether2, got %+v", first)
	}
	if first[0].PktsIn != nil {
		t.Errorf("no rate on the first read, got %v", *first[0].PktsIn)
	}
	var down proto.FlowResult
	for _, f := range first {
		if f.InterfaceID == 2 {
			down = f
		}
	}
	if down.OperUp == nil || *down.OperUp || down.InBps != 0 {
		t.Errorf("down port = %+v", down)
	}

	second := p.routerOSFlows(byName, print("1600"), monitor, t0.Add(12*time.Second))
	if second[0].InterfaceID != 1 || second[0].PktsIn == nil || *second[0].PktsIn != 50 || second[0].InBps != 8000 {
		t.Errorf("ether1 second tick = %+v", second[0])
	}
	if second[0].OperUp == nil || !*second[0].OperUp {
		t.Error("ether1 should be up")
	}
}

func TestRouterOSCPUStorageAndUptime(t *testing.T) {
	cpus := routerOSCPULoads([]map[string]string{{"cpu": "cpu0", "load": "12"}, {"cpu": "cpu1", "load": "140"}, {"cpu": "cpu2"}})
	if len(cpus) != 2 || cpus[0].Index != 0 || cpus[0].LoadPct != 12 || cpus[1].Index != 1 || cpus[1].LoadPct != 100 {
		t.Errorf("cpus = %+v", cpus)
	}

	st := routerOSStorage(map[string]string{"total-memory": "1073741824", "free-memory": "268435456", "total-hdd-space": "134217728", "free-hdd-space": "100663296"})
	if len(st) != 2 || st[0].Key != "memory" || st[0].Used != 805306368 || st[1].Type != "flash" || st[1].Used != 33554432 {
		t.Errorf("storage = %+v", st)
	}
	if st := routerOSStorage(map[string]string{}); st == nil || len(st) != 0 {
		t.Errorf("no sizes should be an empty (read) list, got %#v", st)
	}

	for in, want := range map[string]uint64{"1w2d3h4m5s": 788645, "3h": 10800, "2d03:04:05": 183845} {
		if got := parseRouterOSUptime(in); got == nil || *got != want {
			t.Errorf("parseRouterOSUptime(%q) = %v, want %d", in, got, want)
		}
	}
	if parseRouterOSUptime("") != nil {
		t.Error("empty uptime should be nil")
	}
}

func TestHrStorageEntryWalk(t *testing.T) {
	base := "1.3.6.1.2.1.25.2.3.1"
	walk := func(oid string) ([]gosnmp.SnmpPDU, error) {
		return []gosnmp.SnmpPDU{
			{Name: "." + base + ".2.1", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.2.1.25.2.1.2"},
			{Name: "." + base + ".2.31", Type: gosnmp.ObjectIdentifier, Value: ".1.3.6.1.2.1.25.2.1.4"},
			{Name: "." + base + ".3.1", Type: gosnmp.OctetString, Value: []byte("Physical memory")},
			{Name: "." + base + ".3.31", Type: gosnmp.OctetString, Value: []byte("/")},
			{Name: "." + base + ".4.1", Type: gosnmp.Integer, Value: 1024},
			{Name: "." + base + ".4.31", Type: gosnmp.Integer, Value: 4096},
			{Name: "." + base + ".5.1", Type: gosnmp.Integer, Value: 1000},
			{Name: "." + base + ".5.31", Type: gosnmp.Integer, Value: 2000},
			{Name: "." + base + ".6.1", Type: gosnmp.Integer, Value: 250},
			{Name: "." + base + ".6.31", Type: gosnmp.Integer, Value: 500},
		}, nil
	}
	entry, err := walkRaw(walk, base)
	if err != nil {
		t.Fatal(err)
	}
	st := hrStorageEntries(entry)
	if len(st) != 2 || st[0].Key != "1" || st[1].Key != "31" {
		t.Fatalf("entries = %+v", st)
	}
	if st[1].Descr != "/" || !strings.HasSuffix(st[1].Type, ".4") || st[1].Units != 4096 || st[1].Size != 2000 || st[1].Used != 500 {
		t.Errorf("disk row = %+v", st[1])
	}
	if mem := hrEntryMem(entry); mem == nil || *mem != 25 {
		t.Errorf("memory from the entry = %v, want 25", mem)
	}
	if _, err := walkRaw(func(string) ([]gosnmp.SnmpPDU, error) { return nil, errors.New("timeout") }, base); err == nil {
		t.Error("a failed walk should come back as an error, not an empty table")
	}
}

func TestCPULoadsAndUptime(t *testing.T) {
	loads := cpuLoads(map[string]float64{"196609": 30, "196608": 10})
	if len(loads) != 2 || loads[0].Index != 196608 || loads[1].LoadPct != 30 {
		t.Errorf("cpuLoads = %+v", loads)
	}

	get := func(oids []string) (*gosnmp.SnmpPacket, error) {
		return &gosnmp.SnmpPacket{Variables: []gosnmp.SnmpPDU{
			{Name: ".1.3.6.1.2.1.25.1.1.0", Type: gosnmp.NoSuchObject},
			{Name: ".1.3.6.1.2.1.1.3.0", Type: gosnmp.TimeTicks, Value: uint32(123456)},
		}}, nil
	}
	up := metricUptime(get, []string{".1.3.6.1.2.1.25.1.1.0", ".1.3.6.1.2.1.1.3.0"})
	if up == nil || *up != 1234 {
		t.Errorf("uptime = %v, want sysUpTime 1234s when the host one is absent", up)
	}
}

func TestMetricsResultStorageNullVersusEmpty(t *testing.T) {
	b, _ := json.Marshal(proto.MetricsResult{DeviceID: 1})
	if !strings.Contains(string(b), `"storage":null`) {
		t.Errorf("unread storage should marshal as null: %s", b)
	}
	b, _ = json.Marshal(proto.MetricsResult{DeviceID: 1, Storage: []proto.StorageEntry{}})
	if !strings.Contains(string(b), `"storage":[]`) {
		t.Errorf("read-but-empty storage should marshal as []: %s", b)
	}
}
