package poll

import (
	"errors"
	"reflect"
	"testing"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
)

const (
	wifiCmd    = "/interface/wifi/registration-table/print"
	wave2Cmd   = "/interface/wifiwave2/registration-table/print"
	legacyCmd  = "/interface/wireless/registration-table/print"
	capsmanCmd = "/caps-man/registration-table/print"
)

// fakeRouterOS answers prints from a table; a command not in it traps like a missing menu.
type fakeRouterOS struct {
	replies map[string][]map[string]string
	errs    map[string]error
	calls   []string
}

func (f *fakeRouterOS) run(cmd string) ([]map[string]string, error) {
	f.calls = append(f.calls, cmd)
	if err, ok := f.errs[cmd]; ok {
		return nil, err
	}
	rows, ok := f.replies[cmd]
	if !ok {
		return nil, errors.New("from RouterOS device: no such command prefix")
	}
	return rows, nil
}

func wlTarget() proto.RouterOSTarget {
	return proto.RouterOSTarget{DeviceID: 7, OSVersion: "7.16"}
}

func TestRouterOSWifiRegistrationTable(t *testing.T) {
	f := &fakeRouterOS{replies: map[string][]map[string]string{
		wifiCmd: {
			{"mac-address": "AA:AA:AA:AA:AA:01", "interface": "wifi1", "signal": "-50"},
			{"mac-address": "AA:AA:AA:AA:AA:02", "interface": "wifi2", "signal": "-70"},
		},
	}}
	w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now())

	if w.Clients == nil || *w.Clients != 2 || w.SignalDbm == nil || *w.SignalDbm != -60 {
		t.Fatalf("got clients %v signal %v", w.Clients, w.SignalDbm)
	}
	if w.SnrDb != nil || w.CcqPct != nil {
		t.Errorf("wifi has no snr/ccq, got %v / %v", w.SnrDb, w.CcqPct)
	}
	if want := []string{wifiCmd, legacyCmd}; !reflect.DeepEqual(f.calls, want) {
		t.Errorf("calls = %v, want %v (no wifiwave2 once wifi answered)", f.calls, want)
	}
}

func TestRouterOSWifiwave2Fallback(t *testing.T) {
	f := &fakeRouterOS{replies: map[string][]map[string]string{
		wave2Cmd: {
			{"mac-address": "AA:AA:AA:AA:AA:01", "signal": "-61"},
			{"mac-address": "AA:AA:AA:AA:AA:02", "signal-strength-ch0": "-72", "signal-strength-ch1": "-68"},
			{"mac-address": "AA:AA:AA:AA:AA:03"},
		},
	}}
	w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now())

	if w.Clients == nil || *w.Clients != 3 {
		t.Errorf("clients = %v, want 3", w.Clients)
	}
	if w.SignalDbm == nil || *w.SignalDbm != -64.5 {
		t.Errorf("signal = %v, want avg(-61, strongest chain -68)", w.SignalDbm)
	}
	if w.CcqPct != nil {
		t.Errorf("ccq = %v, want nil", *w.CcqPct)
	}
	if want := []string{wifiCmd, wave2Cmd, legacyCmd}; !reflect.DeepEqual(f.calls, want) {
		t.Errorf("calls = %v, want %v", f.calls, want)
	}
}

func TestRouterOSLegacyFallbackKeepsCCQ(t *testing.T) {
	f := &fakeRouterOS{replies: map[string][]map[string]string{
		legacyCmd: {
			{"mac-address": "AA:AA:AA:AA:AA:01", "signal-strength": "-65dBm@6Mbps", "signal-to-noise": "30", "tx-ccq": "90"},
		},
		capsmanCmd: {},
	}}
	w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now())

	if w.Clients == nil || *w.Clients != 1 || *w.SignalDbm != -65 || *w.SnrDb != 30 || *w.CcqPct != 90 {
		t.Errorf("got %+v", w)
	}
}

func TestRouterOSNoWirelessMenusIsEmpty(t *testing.T) {
	f := &fakeRouterOS{}
	if w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now()); !w.Empty() {
		t.Errorf("no menus should be no RF, got %+v", w)
	}
	// an empty table (every 7.13+ router has one) is no RF too, not 0 clients
	f = &fakeRouterOS{replies: map[string][]map[string]string{wifiCmd: {}}}
	if w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now()); !w.Empty() {
		t.Errorf("empty table should be no RF, got %+v", w)
	}
}

func TestRouterOSWirelessStackCache(t *testing.T) {
	p := New()
	now := time.Now()
	f := &fakeRouterOS{replies: map[string][]map[string]string{
		wifiCmd: {{"mac-address": "AA:AA:AA:AA:AA:01", "signal": "-50"}},
	}}

	p.routerOSWirelessRead(wlTarget(), f.run, now)
	f.calls = nil
	w := p.routerOSWirelessRead(wlTarget(), f.run, now.Add(time.Minute))
	if want := []string{wifiCmd}; !reflect.DeepEqual(f.calls, want) {
		t.Errorf("cached poll calls = %v, want %v", f.calls, want)
	}
	if w.Clients == nil || *w.Clients != 1 {
		t.Errorf("clients = %v", w.Clients)
	}

	// a new os_version is a new key, probed again
	f.calls = nil
	up := wlTarget()
	up.OSVersion = "7.17"
	p.routerOSWirelessRead(up, f.run, now.Add(2*time.Minute))
	if want := []string{wifiCmd, legacyCmd}; !reflect.DeepEqual(f.calls, want) {
		t.Errorf("after upgrade calls = %v, want %v", f.calls, want)
	}

	// and it expires
	f.calls = nil
	p.routerOSWirelessRead(wlTarget(), f.run, now.Add(wlStackTTL+time.Hour))
	if len(f.calls) != 2 {
		t.Errorf("expired cache should probe again, calls = %v", f.calls)
	}
}

func TestRouterOSWirelessCachedMenuGoneAndTimeouts(t *testing.T) {
	p := New()
	now := time.Now()
	key := "7|7.16"
	p.wl.put(key, []string{"wireless"}, now)

	f := &fakeRouterOS{}
	if w := p.routerOSWirelessRead(wlTarget(), f.run, now); !w.Empty() {
		t.Errorf("got %+v", w)
	}
	if _, ok := p.wl.get(key, now); ok {
		t.Error("a cached menu that traps should drop the cache entry")
	}

	// a timeout while probing isn't cached
	f = &fakeRouterOS{errs: map[string]error{wifiCmd: errors.New("i/o timeout")}}
	p.routerOSWirelessRead(wlTarget(), f.run, now)
	if _, ok := p.wl.get(key, now); ok {
		t.Error("a half probe should not be cached")
	}
}

func TestRouterOSCapsmanControllerCountsOnce(t *testing.T) {
	f := &fakeRouterOS{replies: map[string][]map[string]string{
		wifiCmd: {
			{"mac-address": "aa:aa:aa:aa:aa:01", "interface": "cap-wifi1", "signal": "-50"},
			{"mac-address": "AA:AA:AA:AA:AA:02", "interface": "cap-wifi3", "signal": "-60"},
		},
		legacyCmd: {
			{"mac-address": "AA:AA:AA:AA:AA:03", "signal-strength": "-70", "signal-to-noise": "25", "tx-ccq": "80"},
		},
		capsmanCmd: {
			{"mac-address": "AA:AA:AA:AA:AA:04", "interface": "cap1", "rx-signal": "-80"},
			{"mac-address": "AA:AA:AA:AA:AA:01", "interface": "cap2", "rx-signal": "-90"},
		},
	}}
	w := New().routerOSWirelessRead(wlTarget(), f.run, time.Now())

	if w.Clients == nil || *w.Clients != 4 {
		t.Errorf("clients = %v, want 4 (one station in two tables counted once)", w.Clients)
	}
	if *w.SignalDbm != -65 || *w.SnrDb != 25 || *w.CcqPct != 80 {
		t.Errorf("signal/snr/ccq = %v / %v / %v", *w.SignalDbm, *w.SnrDb, *w.CcqPct)
	}
	if want := []string{wifiCmd, legacyCmd, capsmanCmd}; !reflect.DeepEqual(f.calls, want) {
		t.Errorf("calls = %v, want %v", f.calls, want)
	}
}
