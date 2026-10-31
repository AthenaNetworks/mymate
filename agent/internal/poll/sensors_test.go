package poll

import (
	"testing"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

func TestPduValueRendersLikeWalkRaw(t *testing.T) {
	cases := []struct {
		pdu  gosnmp.SnmpPDU
		want string
		ok   bool
	}{
		{gosnmp.SnmpPDU{Type: gosnmp.Integer, Value: 42}, "42", true},
		{gosnmp.SnmpPDU{Type: gosnmp.Gauge32, Value: uint(7)}, "7", true},
		{gosnmp.SnmpPDU{Type: gosnmp.Counter64, Value: uint64(1) << 40}, "1099511627776", true},
		{gosnmp.SnmpPDU{Type: gosnmp.OctetString, Value: []byte("23.5 C")}, "23.5 C", true},
		{gosnmp.SnmpPDU{Type: gosnmp.NoSuchObject}, "", false},
		{gosnmp.SnmpPDU{Type: gosnmp.NoSuchInstance}, "", false},
	}
	for _, c := range cases {
		got, ok := pduValue(c.pdu)
		if got != c.want || ok != c.ok {
			t.Errorf("pduValue(%v) = %q, %v; want %q, %v", c.pdu.Type, got, ok, c.want, c.ok)
		}
	}
}

func TestSensorsSkippedWithoutTargets(t *testing.T) {
	if got := New().pollSNMPSensors(proto.SNMPTarget{IP: "192.0.2.1"}); got != nil {
		t.Fatalf("expected no reads, got %v", got)
	}
}

func TestSNMPTestEchoesRequestID(t *testing.T) {
	// No OID: fails before any network I/O, but must still answer with the request id so the
	// server can match the reply.
	r := New().TestSNMP(proto.SNMPTest{RequestID: "abc", IP: "127.0.0.1", OID: ""})
	if r.RequestID != "abc" || r.Type != "snmp_test_result" || r.Error == "" {
		t.Fatalf("unexpected result %+v", r)
	}
}
