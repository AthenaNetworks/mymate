package poll

import (
	"strconv"
	"strings"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/gosnmp/gosnmp"
)

// pollSNMPMetrics reads cpu / memory / temperature for one device using the OID profile the
// server resolved for it (MetricsTarget). Each metric is best-effort and independent - an OID
// the device doesn't implement just leaves that metric nil, mirroring the central
// SnmpDeviceMetricsDriver so an agent-polled device and a centrally-polled one read the same.
func (p *Poller) pollSNMPMetrics(t proto.SNMPTarget) *proto.MetricsResult {
	if t.Metrics == nil {
		return nil
	}
	g, err := dialSNMP(t.IP, t.Community, t.SNMP)
	if err != nil {
		return nil
	}
	defer g.Conn.Close()

	m := t.Metrics
	cpu := metricCPU(g, m)
	mem := metricMem(g, m)
	temp := metricTemp(g, m)
	if cpu == nil && mem == nil && temp == nil {
		return nil // nothing readable - don't report an all-null frame
	}
	return &proto.MetricsResult{DeviceID: t.DeviceID, CPUPct: clampPct(cpu), MemUsedPct: clampPct(mem), TempC: temp}
}

func metricCPU(g *gosnmp.GoSNMP, m *proto.MetricsTarget) *float64 {
	if m.CPUWalk != "" {
		vals := walkNumbers(g, m.CPUWalk)
		if len(vals) == 0 {
			return nil
		}
		return avg(vals) // average across cores
	}
	for _, oid := range m.CPUOids {
		if v := firstNumber(g, oid); v != nil {
			return v
		}
	}
	return nil
}

func metricMem(g *gosnmp.GoSNMP, m *proto.MetricsTarget) *float64 {
	switch m.Mem {
	case "hrstorage":
		return hrStorageMem(g, m)
	case "cisco":
		used := sum(walkNumbers(g, m.MemUsedWalk))
		free := sum(walkNumbers(g, m.MemFreeWalk))
		total := used + free
		if total <= 0 {
			return nil
		}
		v := used / total * 100
		return &v
	}
	return nil
}

// hrStorageMem walks the host-resources storage table and picks the physical-RAM row (largest
// size among memory rows, skipping virtual/swap/cache/buffer), reporting used/size %.
func hrStorageMem(g *gosnmp.GoSNMP, m *proto.MetricsTarget) *float64 {
	descr := walkStrings(g, m.HrDescr)
	size := walkNumbersByIndex(g, m.HrSize)
	used := walkNumbersByIndex(g, m.HrUsed)

	bestIdx, bestSize := "", 0.0
	for idx, label := range descr {
		l := strings.ToLower(label)
		isRAM := strings.Contains(l, "physical memory") || strings.Contains(l, "real memory") ||
			strings.Contains(l, "main memory") || l == "memory" ||
			(strings.Contains(l, "ram") && !strings.Contains(l, "virtual"))
		isRAM = isRAM && !strings.Contains(l, "virtual") && !strings.Contains(l, "swap") &&
			!strings.Contains(l, "cache") && !strings.Contains(l, "buffer")
		if isRAM {
			if s, ok := size[idx]; ok && s > bestSize {
				bestSize, bestIdx = s, idx
			}
		}
	}
	if bestIdx == "" || bestSize <= 0 {
		return nil
	}
	u, ok := used[bestIdx]
	if !ok {
		return nil
	}
	v := u / bestSize * 100
	return &v
}

func metricTemp(g *gosnmp.GoSNMP, m *proto.MetricsTarget) *float64 {
	div := m.TempDivisor
	if div < 1 {
		div = 1
	}
	var vals []float64
	if m.TempWalk != "" {
		vals = append(vals, walkNumbers(g, m.TempWalk)...)
	}
	for _, oid := range m.TempOids {
		if v := firstNumber(g, oid); v != nil {
			vals = append(vals, *v)
		}
	}
	// Ignore obvious non-readings (0 / sentinel); take the hottest real sensor.
	max := 0.0
	found := false
	for _, v := range vals {
		if v > 0 && (!found || v > max) {
			max, found = v, true
		}
	}
	if !found {
		return nil
	}
	v := max / float64(div)
	return &v
}

// --- SNMP value helpers ---------------------------------------------------

// walkNumbers returns every numeric value under baseOid (index discarded).
func walkNumbers(g *gosnmp.GoSNMP, oid string) []float64 {
	var out []float64
	for _, v := range walkNumbersByIndex(g, oid) {
		out = append(out, v)
	}
	return out
}

// walkNumbersByIndex walks baseOid and returns numeric values keyed by the row suffix (index).
func walkNumbersByIndex(g *gosnmp.GoSNMP, oid string) map[string]float64 {
	out := map[string]float64{}
	if oid == "" {
		return out
	}
	pdus, err := g.WalkAll(oid)
	if err != nil {
		return out
	}
	for _, pdu := range pdus {
		if f, ok := pduFloat(pdu); ok {
			out[suffix(oid, pdu.Name)] = f
		}
	}
	return out
}

// walkStrings walks baseOid and returns string values keyed by the row suffix.
func walkStrings(g *gosnmp.GoSNMP, oid string) map[string]string {
	out := map[string]string{}
	if oid == "" {
		return out
	}
	pdus, err := g.WalkAll(oid)
	if err != nil {
		return out
	}
	for _, pdu := range pdus {
		out[suffix(oid, pdu.Name)] = pduString(pdu)
	}
	return out
}

// firstNumber GETs a scalar OID and returns its numeric value, or nil.
func firstNumber(g *gosnmp.GoSNMP, oid string) *float64 {
	res, err := g.Get([]string{oid})
	if err != nil || len(res.Variables) == 0 {
		return nil
	}
	if f, ok := pduFloat(res.Variables[0]); ok {
		return &f
	}
	return nil
}

func pduFloat(pdu gosnmp.SnmpPDU) (float64, bool) {
	switch pdu.Type {
	case gosnmp.OctetString:
		s := strings.TrimSpace(pduString(pdu))
		f, err := strconv.ParseFloat(s, 64)
		return f, err == nil
	case gosnmp.Null, gosnmp.NoSuchObject, gosnmp.NoSuchInstance, gosnmp.EndOfMibView:
		return 0, false
	default:
		return float64(gosnmp.ToBigInt(pdu.Value).Int64()), true
	}
}

func pduString(pdu gosnmp.SnmpPDU) string {
	if b, ok := pdu.Value.([]byte); ok {
		return string(b)
	}
	if s, ok := pdu.Value.(string); ok {
		return s
	}
	return ""
}

// suffix returns the part of name after baseOid (the table row index), tolerating a leading dot.
func suffix(base, name string) string {
	name = strings.TrimPrefix(name, ".")
	base = strings.TrimPrefix(base, ".")
	return strings.TrimPrefix(strings.TrimPrefix(name, base), ".")
}

func avg(v []float64) *float64 {
	if len(v) == 0 {
		return nil
	}
	r := sum(v) / float64(len(v))
	return &r
}

func sum(v []float64) float64 {
	t := 0.0
	for _, x := range v {
		t += x
	}
	return t
}

// clampPct bounds a percentage to 0..100 (matches DeviceMetrics::clampPct); passes nil through.
func clampPct(v *float64) *float64 {
	if v == nil {
		return nil
	}
	r := *v
	if r < 0 {
		r = 0
	}
	if r > 100 {
		r = 100
	}
	return &r
}
