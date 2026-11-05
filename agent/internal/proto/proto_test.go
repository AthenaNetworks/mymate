package proto

import (
	"encoding/json"
	"strings"
	"testing"
)

// mtxr_version is optional both ways (GH #60): left out when unread, so an older server sees
// exactly what it always did, and a payload without it still decodes.
func TestDeviceFactsMtxrVersionIsOptional(t *testing.T) {
	b, err := json.Marshal(DeviceFacts{SysDescr: "RouterOS RB750Gr3"})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(b), "mtxr_version") {
		t.Fatalf("empty mtxr_version should be omitted: %s", b)
	}

	b, _ = json.Marshal(DeviceFacts{SysDescr: "RouterOS RB750Gr3", MtxrVersion: "6.49.17"})
	if !strings.Contains(string(b), `"mtxr_version":"6.49.17"`) {
		t.Fatalf("mtxr_version missing: %s", b)
	}

	var f DeviceFacts
	if err := json.Unmarshal([]byte(`{"sys_descr":"RouterOS RB750Gr3"}`), &f); err != nil || f.MtxrVersion != "" {
		t.Fatalf("decode without mtxr_version: %v %+v", err, f)
	}
}
