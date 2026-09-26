package poll

import (
	"encoding/json"
	"errors"
	"testing"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

func TestOpticalPortsScalesAndDropsEmptyRows(t *testing.T) {
	// MikroTik mtxrOpticalTable: thousandths of a dBm, indexed by ifIndex.
	rx := map[string]float64{"5": -5123, "6": -40000, "7": -99999999}
	tx := map[string]float64{"5": -2250}
	names := map[string]string{"5": "sfp1"}

	ports := opticalPorts(rx, tx, names, 1000)
	byIdx := map[int]proto.OpticalPort{}
	for _, p := range ports {
		byIdx[p.IfIndex] = p
	}

	if len(ports) != 2 {
		t.Fatalf("want 2 ports (row 7 is a sentinel), got %d: %+v", len(ports), ports)
	}
	p5 := byIdx[5]
	if p5.Name != "sfp1" || p5.RxDbm == nil || *p5.RxDbm != -5.123 || p5.TxDbm == nil || *p5.TxDbm != -2.25 {
		t.Errorf("port 5 = %+v", p5)
	}
	// No light at all is still a real (alarming) reading, not dropped.
	if p6 := byIdx[6]; p6.RxDbm == nil || *p6.RxDbm != -40 || p6.TxDbm != nil {
		t.Errorf("port 6 = %+v", p6)
	}
}

func TestWalkOpticalReportsFailureSeparatelyFromEmpty(t *testing.T) {
	walk := func(oid string) ([]gosnmp.SnmpPDU, error) {
		if oid == "1.2.3" {
			return []gosnmp.SnmpPDU{{Name: ".1.2.3.9", Type: gosnmp.Integer, Value: -3100}}, nil
		}
		return nil, errors.New("timeout")
	}

	got, err := walkOptical(walk, "1.2.3")
	if err != nil || got["9"] != -3100 {
		t.Fatalf("walkOptical = %v, %v", got, err)
	}
	if _, err := walkOptical(walk, "9.9.9"); err == nil {
		t.Fatal("expected the walk error to come back")
	}
	if got, err := walkOptical(walk, ""); err != nil || len(got) != 0 {
		t.Fatalf("blank oid should be empty, got %v, %v", got, err)
	}
}

func TestRouterOSOpticalPorts(t *testing.T) {
	rows := []map[string]string{
		{"name": "sfp-sfpplus1", "sfp-rx-power": "-7.321", "sfp-tx-power": "-1.998"},
		{"name": "ether1", "rate": "1Gbps"},             // copper, no module fields
		{"name": "sfp2", "sfp-module-present": "false"}, // empty cage
		{"name": "sfp3", "sfp-rx-power": "-12.5dBm"},    // RouterOS 6 style, rx only
	}

	ports := routerOSOpticalPorts(rows)
	if len(ports) != 2 {
		t.Fatalf("want 2 ports, got %+v", ports)
	}
	if ports[0].Name != "sfp-sfpplus1" || *ports[0].RxDbm != -7.321 || *ports[0].TxDbm != -1.998 {
		t.Errorf("ports[0] = %+v", ports[0])
	}
	if ports[1].Name != "sfp3" || *ports[1].RxDbm != -12.5 || ports[1].TxDbm != nil {
		t.Errorf("ports[1] = %+v", ports[1])
	}
}

// Old servers never send an optical target, and a missing one must leave the agent doing
// nothing extra (no SNMP dial, no API login).
func TestOpticalSkippedWhenNotRequested(t *testing.T) {
	p := New()
	if o := p.pollSNMPOptical(proto.SNMPTarget{DeviceID: 1, IP: "192.0.2.1"}); o != nil {
		t.Errorf("snmp optical without a target = %+v", o)
	}
	if o := p.pollRouterOSOptical(proto.RouterOSTarget{DeviceID: 1, IP: "192.0.2.1"}); o != nil {
		t.Errorf("routeros optical without the flag = %+v", o)
	}

	var job proto.PollJob
	raw := `{"ping":[],"snmp":[{"device_id":1,"ip":"192.0.2.1","community":"x","interfaces":[]}],"routeros":[]}`
	if err := json.Unmarshal([]byte(raw), &job); err != nil {
		t.Fatal(err)
	}
	if job.SNMP[0].Optical != nil {
		t.Error("a job without optical should decode to a nil target")
	}
}

// A port that only reports one direction must marshal the other as null, not 0 dBm.
func TestOpticalPortMarshalsMissingPowerAsNull(t *testing.T) {
	rx := -4.0
	b, err := json.Marshal(proto.OpticalPort{Name: "sfp1", RxDbm: &rx})
	if err != nil {
		t.Fatal(err)
	}
	if string(b) != `{"name":"sfp1","rx_dbm":-4,"tx_dbm":null}` {
		t.Errorf("got %s", b)
	}
}
