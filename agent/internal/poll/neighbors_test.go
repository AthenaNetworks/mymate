package poll

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

func strPDU(name string, v []byte) gosnmp.SnmpPDU {
	return gosnmp.SnmpPDU{Name: name, Type: gosnmp.OctetString, Value: v}
}

func TestReadNeighborsSendsLLDPRawWithBinaryAsHex(t *testing.T) {
	walk := fakeWalk(map[string][]gosnmp.SnmpPDU{
		"1.0.8802.1.1.2.1.4.1.1.5": {strPDU(".1.0.8802.1.1.2.1.4.1.1.5.0.29.1", []byte{0, 0x11, 0x22, 0x33, 0x44, 0x55})},
		"1.0.8802.1.1.2.1.4.1.1.9": {strPDU(".1.0.8802.1.1.2.1.4.1.1.9.0.29.1", []byte("core1"))},
		"1.0.8802.1.1.2.1.4.1.1.6": {intPDU(".1.0.8802.1.1.2.1.4.1.1.6.0.29.1", 5)},
	})
	get := func([]string) (*gosnmp.SnmpPacket, error) {
		return &gosnmp.SnmpPacket{Variables: []gosnmp.SnmpPDU{strPDU(".1.0.8802.1.1.2.1.3.2.0", []byte{0xaa, 0xbb, 0xcc, 0xdd, 0xee, 0xff})}}, nil
	}

	nt := readNeighbors(get, walk)
	if nt == nil {
		t.Fatal("want tables")
	}
	if got := nt.Walks["1.0.8802.1.1.2.1.4.1.1.5"]["0.29.1"]; got != "hex:001122334455" {
		t.Errorf("chassis = %q", got)
	}
	if got := nt.Walks["1.0.8802.1.1.2.1.4.1.1.9"]["0.29.1"]; got != "core1" {
		t.Errorf("sysname = %q", got)
	}
	if got := nt.Walks["1.0.8802.1.1.2.1.4.1.1.6"]["0.29.1"]; got != "5" {
		t.Errorf("subtype = %q", got)
	}
	if got := nt.Scalars[oidLldpLocChassisID]; got != "hex:aabbccddeeff" {
		t.Errorf("local chassis = %q", got)
	}
	// The JSON survives (raw bytes would have been mangled into U+FFFD).
	b, _ := json.Marshal(nt)
	if !strings.Contains(string(b), `"hex:001122334455"`) {
		t.Errorf("json = %s", b)
	}
}

func TestReadNeighborsSkipsEmptyTablesAndStopsOnAWalkError(t *testing.T) {
	var asked []string
	walk := func(oid string) ([]gosnmp.SnmpPDU, error) {
		asked = append(asked, oid)
		return nil, nil
	}
	none := func([]string) (*gosnmp.SnmpPacket, error) { return nil, errors.New("timeout") }

	if nt := readNeighbors(none, walk); nt != nil {
		t.Errorf("no neighbours and no chassis should be nil, got %+v", nt)
	}
	// Only each table's key column was asked for.
	if len(asked) != 2 {
		t.Errorf("asked %v", asked)
	}

	asked = nil
	failing := func(oid string) ([]gosnmp.SnmpPDU, error) {
		asked = append(asked, oid)
		return nil, errors.New("timeout")
	}
	readNeighbors(none, failing)
	if len(asked) != 1 {
		t.Errorf("a failed walk should stop the pass, asked %v", asked)
	}
}

// Both directions of agent/COMPATIBILITY.md: an older server never sees the field when there's
// nothing to send, and a payload from an older agent (no neighbors) still decodes.
func TestNeighborsIsOptionalOnTheWire(t *testing.T) {
	b, _ := json.Marshal(proto.DeviceDiscovery{DeviceID: 1})
	if strings.Contains(string(b), "neighbors") {
		t.Errorf("empty neighbors should be omitted: %s", b)
	}
	var d proto.DeviceDiscovery
	if err := json.Unmarshal([]byte(`{"device_id":1,"interfaces":[]}`), &d); err != nil || d.Neighbors != nil {
		t.Errorf("decode without neighbors: %v %+v", err, d)
	}
}
