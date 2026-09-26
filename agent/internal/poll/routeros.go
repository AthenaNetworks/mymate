package poll

import (
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/go-routeros/routeros/v3"
)

// routerOSPortFields maps the /interface/print counters to the port rate names the server uses.
// They're all 64-bit on RouterOS.
var routerOSPortFields = map[string]string{
	"pkts_in": "rx-packet", "pkts_out": "tx-packet",
	"errors_in": "rx-error", "errors_out": "tx-error",
	"discards_in": "rx-drop", "discards_out": "tx-drop",
}

// pollRouterOS reads throughput from a MikroTik over the RouterOS API using
// `/interface/monitor-traffic once`, which returns rx/tx bits-per-second directly (no
// counter delta needed - same as the central RouterOS driver). Matched to our interfaces
// by name.
//
// Like the central driver it also reads `/interface/print` first: `running` gives each port's
// oper status (a down port is reported as 0 bps + down even when monitor-traffic leaves it out)
// and the same rows carry the packet / error / drop counters, turned into rates every tick.
func (p *Poller) pollRouterOS(t proto.RouterOSTarget) []proto.FlowResult {
	if len(t.Interfaces) == 0 {
		return nil
	}
	port := t.APIPort
	if port == 0 {
		port = 8728
	}

	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", t.IP, port), t.Username, t.Password, 3*time.Second)
	if err != nil {
		return nil // filtered port / bad creds - skip this device, don't sink the batch
	}
	defer c.Close()

	byName := make(map[string]int, len(t.Interfaces))
	names := make([]string, 0, len(t.Interfaces))
	for _, i := range t.Interfaces {
		if i.Name == "" {
			continue
		}
		byName[i.Name] = i.InterfaceID
		names = append(names, i.Name)
	}
	if len(names) == 0 {
		return nil
	}

	var printRows []map[string]string
	if reply, err := c.Run("/interface/print", "=.proplist=name,running,rx-packet,tx-packet,rx-error,tx-error,rx-drop,tx-drop"); err == nil {
		for _, re := range reply.Re {
			printRows = append(printRows, re.Map)
		}
	}

	// A monitor-traffic failure still leaves the down ports from the print, like the server.
	var monitorRows []map[string]string
	if reply, err := c.Run("/interface/monitor-traffic", "=interface="+strings.Join(names, ","), "=once="); err == nil {
		for _, re := range reply.Re {
			monitorRows = append(monitorRows, re.Map)
		}
	}
	if printRows == nil && monitorRows == nil {
		return nil
	}

	return p.routerOSFlows(byName, printRows, monitorRows, time.Now())
}

// routerOSFlows merges the print (oper status + counters) and monitor-traffic (bps) replies into
// flows, keyed to our interfaces by name.
func (p *Poller) routerOSFlows(byName map[string]int, printRows, monitorRows []map[string]string, now time.Time) []proto.FlowResult {
	type portInfo struct {
		up    *bool
		rates map[string]*float64
	}
	info := map[string]portInfo{}
	for _, row := range printRows {
		id, ok := byName[row["name"]]
		if !ok {
			continue
		}
		pi := portInfo{up: boolFlag(row["running"])}
		if c := routerOSPortCounters(row); len(c) > 0 {
			pi.rates = p.state.portRates(id, c, nil, now)
		}
		info[row["name"]] = pi
	}

	flows := make([]proto.FlowResult, 0, len(byName))
	seen := map[string]bool{}
	add := func(name string, in, out float64) {
		pi := info[name]
		f := proto.FlowResult{InterfaceID: byName[name], InBps: in, OutBps: out, OperUp: pi.up}
		for n, r := range pi.rates {
			f.SetPortRate(n, r)
		}
		flows = append(flows, f)
		seen[name] = true
	}
	for _, re := range monitorRows {
		name := re["name"]
		if _, ok := byName[name]; !ok || seen[name] {
			continue
		}
		add(name, parseFloat(re["rx-bits-per-second"]), parseFloat(re["tx-bits-per-second"]))
	}
	// Down ports monitor-traffic skipped: no traffic, and the down state is the useful bit.
	downs := make([]string, 0)
	for name, pi := range info {
		if !seen[name] && pi.up != nil && !*pi.up {
			downs = append(downs, name)
		}
	}
	sort.Strings(downs)
	for _, name := range downs {
		add(name, 0, 0)
	}
	return flows
}

// routerOSPortCounters pulls the counters out of one /interface/print row. A field the row
// doesn't have (some virtual interfaces) is left out.
func routerOSPortCounters(row map[string]string) map[string]uint64 {
	out := map[string]uint64{}
	for name, field := range routerOSPortFields {
		v, ok := row[field]
		if !ok || strings.TrimSpace(v) == "" {
			continue
		}
		if n, err := strconv.ParseUint(strings.TrimSpace(v), 10, 64); err == nil {
			out[name] = n
		}
	}
	return out
}

func parseFloat(s string) float64 {
	f, _ := strconv.ParseFloat(strings.TrimSpace(s), 64)
	return f
}

// pollRouterOSMetrics reads cpu / memory / temperature over the RouterOS API - the same
// /system/resource (cpu-load + free/total memory) and best-effort /system/health the central
// RouterOsDeviceMetricsDriver uses. Returns nil when nothing is readable.
//
// For the device page it also reports the uptime and the memory / system disk sizes (both from
// the same /system/resource row) and the load per core from /system/resource/cpu.
func (p *Poller) pollRouterOSMetrics(t proto.RouterOSTarget) *proto.MetricsResult {
	port := t.APIPort
	if port == 0 {
		port = 8728
	}
	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", t.IP, port), t.Username, t.Password, 3*time.Second)
	if err != nil {
		return nil
	}
	defer c.Close()

	var cpu, mem, temp *float64
	var uptime *uint64
	var storage []proto.StorageEntry
	if reply, err := c.Run("/system/resource/print"); err == nil && len(reply.Re) > 0 {
		r := reply.Re[0].Map
		if v, ok := r["cpu-load"]; ok && v != "" {
			f := parseFloat(v)
			cpu = clampPct(&f)
		}
		total, free := parseFloat(r["total-memory"]), parseFloat(r["free-memory"])
		if total > 0 {
			f := (total - free) / total * 100
			mem = clampPct(&f)
		}
		uptime = parseRouterOSUptime(r["uptime"])
		storage = routerOSStorage(r)
	}
	// /system/health is unavailable on some boards - never let it fail the read.
	if reply, err := c.Run("/system/health/print"); err == nil {
		max, found := 0.0, false
		for _, re := range reply.Re {
			for _, key := range []string{"cpu-temperature", "temperature", "board-temperature"} {
				if v, ok := re.Map[key]; ok && v != "" {
					if f := parseFloat(v); f > 0 && (!found || f > max) {
						max, found = f, true
					}
				}
			}
			// RouterOS 7: one row per sensor, {name: "...temperature", value: "42"}.
			if strings.Contains(strings.ToLower(re.Map["name"]), "temperature") {
				if v, ok := re.Map["value"]; ok && v != "" {
					if f := parseFloat(v); f > 0 && (!found || f > max) {
						max, found = f, true
					}
				}
			}
		}
		if found {
			temp = &max
		}
	}
	var cpus []proto.CPULoad
	if reply, err := c.Run("/system/resource/cpu/print"); err == nil {
		rows := make([]map[string]string, 0, len(reply.Re))
		for _, re := range reply.Re {
			rows = append(rows, re.Map)
		}
		cpus = routerOSCPULoads(rows)
	}

	if cpu == nil && mem == nil && temp == nil && uptime == nil && len(cpus) == 0 && len(storage) == 0 {
		return nil
	}
	return &proto.MetricsResult{DeviceID: t.DeviceID, CPUPct: cpu, MemUsedPct: mem, TempC: temp, UptimeS: uptime, CPUs: cpus, Storage: storage}
}

var trailingDigits = regexp.MustCompile(`(\d+)$`)

// routerOSCPULoads turns /system/resource/cpu rows (cpu=cpu0, load=12) into per-core loads,
// indexed by the core number (row order when the name has none).
func routerOSCPULoads(rows []map[string]string) []proto.CPULoad {
	var out []proto.CPULoad
	for i, row := range rows {
		v, ok := row["load"]
		if !ok || strings.TrimSpace(v) == "" {
			continue
		}
		idx := i
		if m := trailingDigits.FindStringSubmatch(row["cpu"]); m != nil {
			idx, _ = strconv.Atoi(m[1])
		}
		l := parseFloat(v)
		out = append(out, proto.CPULoad{Index: idx, LoadPct: *clampPct(&l)})
	}
	return out
}

// routerOSStorage is main memory and the system disk out of the /system/resource row, keyed like
// the central driver ("memory", "system-disk") and already typed, sizes in bytes (units 1).
func routerOSStorage(r map[string]string) []proto.StorageEntry {
	out := []proto.StorageEntry{}
	for _, e := range []struct{ key, descr, typ, total, free string }{
		{"memory", "main memory", "ram", "total-memory", "free-memory"},
		{"system-disk", "system disk", "flash", "total-hdd-space", "free-hdd-space"},
	} {
		total := int64(parseFloat(r[e.total]))
		if total <= 0 {
			continue
		}
		used := total - int64(parseFloat(r[e.free]))
		if used < 0 {
			used = 0
		}
		out = append(out, proto.StorageEntry{Key: e.key, Descr: e.descr, Type: e.typ, Units: 1, Size: total, Used: used})
	}
	return out
}

var uptimePart = regexp.MustCompile(`(\d+)([wdhms])`)

// parseRouterOSUptime reads "1w2d03:04:05" / "3d4h5m6s" style uptime into seconds, the same as
// the server's CaptureDeviceFacts::parseRouterOsUptime. nil when there's nothing to parse.
func parseRouterOSUptime(s string) *uint64 {
	s = strings.ToLower(strings.TrimSpace(s))
	if s == "" {
		return nil
	}
	var total uint64
	found := false
	// RouterOS 6 can end in hh:mm:ss after the w/d part
	if i := strings.LastIndexAny(s, "wd"); strings.Count(s, ":") == 2 {
		clock := s[i+1:]
		parts := strings.Split(clock, ":")
		if len(parts) == 3 {
			h, _ := strconv.ParseUint(parts[0], 10, 64)
			m, _ := strconv.ParseUint(parts[1], 10, 64)
			sec, _ := strconv.ParseUint(parts[2], 10, 64)
			total += h*3600 + m*60 + sec
			found = true
			s = s[:i+1]
		}
	}
	for _, m := range uptimePart.FindAllStringSubmatch(s, -1) {
		n, _ := strconv.ParseUint(m[1], 10, 64)
		switch m[2] {
		case "w":
			total += n * 604800
		case "d":
			total += n * 86400
		case "h":
			total += n * 3600
		case "m":
			total += n * 60
		case "s":
			total += n
		}
		found = true
	}
	if !found {
		return nil
	}
	return &total
}
