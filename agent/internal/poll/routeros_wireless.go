package poll

import (
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
)

// Wireless RF over the RouterOS API for every MikroTik wireless stack, the same as the central
// RouterOsWireless (see there for the long version):
//
//	wifi       /interface/wifi/registration-table        RouterOS 7.13+
//	wifiwave2  /interface/wifiwave2/registration-table   7.12 and older, same menu renamed
//	wireless   /interface/wireless/registration-table    legacy wireless package
//	capsman    /caps-man/registration-table              legacy CAPsMAN controller
//
// Every menu that exists is read and the rows merged (a 7.13+ router always has the empty wifi
// menu from the base package, and can have legacy radios too). wifiwave2 is only tried when wifi
// isn't there, caps-man only when legacy wireless is. What a board has is probed once and kept per
// device + os_version for wlStackTTL, so a normal poll doesn't trap on missing menus every time.
//
// A CAPsMAN controller's table lists the stations of all its CAPs; they're all attributed to the
// controller, de-duplicated by MAC so one station is counted once.

var routerOSWirelessMenus = map[string]string{
	"wifi":      "/interface/wifi/registration-table/print",
	"wifiwave2": "/interface/wifiwave2/registration-table/print",
	"wireless":  "/interface/wireless/registration-table/print",
	"capsman":   "/caps-man/registration-table/print",
}

const wlStackTTL = 6 * time.Hour

// rosRun runs one print and returns its rows. The real one wraps a routeros client; tests fake it.
type rosRun func(cmd string) ([]map[string]string, error)

// wlStackCache remembers which registration table menus a device has.
type wlStackCache struct {
	mu sync.Mutex
	m  map[string]wlStackEntry
}

type wlStackEntry struct {
	stacks []string
	at     time.Time
}

func (c *wlStackCache) get(key string, now time.Time) ([]string, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.m[key]
	if !ok || now.Sub(e.at) > wlStackTTL {
		return nil, false
	}
	return e.stacks, true
}

func (c *wlStackCache) put(key string, stacks []string, now time.Time) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.m == nil {
		c.m = map[string]wlStackEntry{}
	}
	c.m[key] = wlStackEntry{stacks: stacks, at: now}
}

func (c *wlStackCache) forget(key string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.m, key)
}

// isMissingMenu is a !trap for a menu the board doesn't have ("no such command prefix").
// go-routeros returns a trap as an error, anything else (timeout, dropped conn) is a real error.
func isMissingMenu(err error) bool {
	return err != nil && strings.Contains(strings.ToLower(err.Error()), "no such command")
}

type stackRows struct {
	stack string
	rows  []map[string]string
}

// routerOSWirelessRead reads RF for one device. Never fails - a missing menu or a failed print
// just means less (or no) RF this poll.
func (p *Poller) routerOSWirelessRead(t proto.RouterOSTarget, run rosRun, now time.Time) proto.Wireless {
	key := fmt.Sprintf("%d|%s", t.DeviceID, t.OSVersion)
	var got []stackRows

	if stacks, ok := p.wl.get(key, now); ok {
		for _, s := range stacks {
			rows, err := run(routerOSWirelessMenus[s])
			if isMissingMenu(err) {
				p.wl.forget(key) // package removed without a version change, probe again next time
				continue
			}
			if err == nil {
				got = append(got, stackRows{s, rows})
			}
		}
		return routerOSWirelessStacks(got)
	}

	present := []string{}
	complete := true
	try := func(s string) bool {
		rows, err := run(routerOSWirelessMenus[s])
		if err == nil {
			present = append(present, s)
			got = append(got, stackRows{s, rows})
			return true
		}
		if !isMissingMenu(err) {
			complete = false // don't cache a half answer
		}
		return false
	}
	if !try("wifi") {
		try("wifiwave2")
	}
	if try("wireless") {
		try("capsman")
	}
	if complete {
		p.wl.put(key, present, now)
	}
	return routerOSWirelessStacks(got)
}

// routerOSWireless turns legacy wireless registration-table rows into RF.
func routerOSWireless(rows []map[string]string) proto.Wireless {
	return routerOSWirelessStacks([]stackRows{{"wireless", rows}})
}

var signalChain = regexp.MustCompile(`^(signal|signal-strength|rx-signal)-ch\d+$`)

// routerOSWirelessStacks is the merged RF, same as the central RouterOsWireless::summarise: one
// row per associated station (an AP sees its clients, a station sees its AP), so clients is the
// row count (de-duplicated by MAC) and signal / SNR / CCQ the average across the rows that have
// them. Values like "-65dBm@6Mbps" read as their leading number. No rows, no RF - not 0 clients,
// since every 7.13+ router has an empty wifi table.
//
// Fields: legacy wireless signal-strength / signal-to-noise / tx-ccq. wifi and wifiwave2 document
// only `signal` (dBm), no SNR and no CCQ, so CCQ is never read there. signal-strength / rx-signal
// and the per-chain -chN variants are a defensive guess in case a version names it differently,
// the strongest chain standing in for the combined value. caps-man gives rx-signal.
func routerOSWirelessStacks(stacks []stackRows) proto.Wireless {
	var signals, snrs, ccqs []float64
	seen := map[string]bool{}
	clients := 0
	num := func(v string) (float64, bool) {
		m := leadingNumber.FindString(v)
		if m == "" {
			return 0, false
		}
		f, err := strconv.ParseFloat(m, 64)
		return f, err == nil
	}
	for _, s := range stacks {
		for _, row := range s.rows {
			if mac := strings.ToUpper(strings.TrimSpace(row["mac-address"])); mac != "" {
				if seen[mac] {
					continue
				}
				seen[mac] = true
			}
			clients++

			switch s.stack {
			case "wireless":
				if f, ok := num(row["signal-strength"]); ok {
					signals = append(signals, f)
				}
			case "capsman":
				v := row["rx-signal"]
				if v == "" {
					v = row["signal-strength"]
				}
				if f, ok := num(v); ok {
					signals = append(signals, f)
				}
			default:
				if f, ok := wifiSignal(row, num); ok {
					signals = append(signals, f)
				}
			}
			if f, ok := num(row["signal-to-noise"]); ok {
				snrs = append(snrs, f)
			}
			if s.stack == "wireless" || s.stack == "capsman" {
				if f, ok := num(row["tx-ccq"]); ok {
					ccqs = append(ccqs, f)
				}
			}
		}
	}
	if clients == 0 {
		return proto.Wireless{}
	}
	avg1 := func(v []float64) *float64 {
		if len(v) == 0 {
			return nil
		}
		r := math.Round(sum(v)/float64(len(v))*10) / 10
		return &r
	}
	return proto.Wireless{SignalDbm: avg1(signals), SnrDb: avg1(snrs), CcqPct: clampPct(avg1(ccqs)), Clients: &clients}
}

func wifiSignal(row map[string]string, num func(string) (float64, bool)) (float64, bool) {
	for _, f := range []string{"signal", "signal-strength", "rx-signal"} {
		if v, ok := num(row[f]); ok {
			return v, true
		}
	}
	best, found := 0.0, false
	for k, v := range row {
		if !signalChain.MatchString(k) {
			continue
		}
		if f, ok := num(v); ok && (!found || f > best) {
			best, found = f, true
		}
	}
	return best, found
}
