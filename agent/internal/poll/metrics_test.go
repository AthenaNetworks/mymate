package poll

import (
	"testing"

	"github.com/gosnmp/gosnmp"
)

func TestAuthProtoMapping(t *testing.T) {
	cases := map[string]gosnmp.SnmpV3AuthProtocol{
		"MD5":     gosnmp.MD5,
		"SHA":     gosnmp.SHA,
		"SHA-224": gosnmp.SHA224,
		"SHA-256": gosnmp.SHA256,
		"SHA-512": gosnmp.SHA512,
		"":        gosnmp.SHA, // default
	}
	for in, want := range cases {
		if got := authProto(in); got != want {
			t.Errorf("authProto(%q) = %v, want %v", in, got, want)
		}
	}
}

func TestPrivProtoMapping(t *testing.T) {
	cases := map[string]gosnmp.SnmpV3PrivProtocol{
		"DES":     gosnmp.DES,
		"AES":     gosnmp.AES,
		"AES-192": gosnmp.AES192,
		"AES-256": gosnmp.AES256,
		"":        gosnmp.AES, // default
	}
	for in, want := range cases {
		if got := privProto(in); got != want {
			t.Errorf("privProto(%q) = %v, want %v", in, got, want)
		}
	}
}

func TestMsgFlags(t *testing.T) {
	if msgFlags("noAuthNoPriv") != gosnmp.NoAuthNoPriv {
		t.Error("noAuthNoPriv should map to NoAuthNoPriv")
	}
	if msgFlags("authNoPriv") != gosnmp.AuthNoPriv {
		t.Error("authNoPriv should map to AuthNoPriv")
	}
	if msgFlags("authPriv") != gosnmp.AuthPriv {
		t.Error("authPriv should map to AuthPriv")
	}
	if msgFlags("") != gosnmp.AuthPriv {
		t.Error("empty level should default to AuthPriv")
	}
}

func TestSuffix(t *testing.T) {
	// Row index is what remains of the OID after the walked base, dot-tolerant.
	if got := suffix("1.3.6.1.2.1.25.2.3.1.6", ".1.3.6.1.2.1.25.2.3.1.6.1"); got != "1" {
		t.Errorf("suffix = %q, want 1", got)
	}
	if got := suffix(".1.3.6.1.2.1.25.2.3.1.6", "1.3.6.1.2.1.25.2.3.1.6.65536"); got != "65536" {
		t.Errorf("suffix = %q, want 65536", got)
	}
}

func TestClampPct(t *testing.T) {
	if clampPct(nil) != nil {
		t.Error("nil should pass through")
	}
	over, under := 150.0, -5.0
	if got := *clampPct(&over); got != 100 {
		t.Errorf("clamp 150 = %v, want 100", got)
	}
	if got := *clampPct(&under); got != 0 {
		t.Errorf("clamp -5 = %v, want 0", got)
	}
}
