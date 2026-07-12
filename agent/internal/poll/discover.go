package poll

import (
	"context"
	"encoding/binary"
	"fmt"
	"net"
	"sync"
	"time"

	"github.com/AthenaNetworks/mymate/agent/internal/proto"
	"github.com/go-routeros/routeros/v3"
	"github.com/gosnmp/gosnmp"
)

const (
	oidSysName   = "1.3.6.1.2.1.1.5.0" // sysName.0
	maxScanHosts = 4096                // keep this in sync with the central Scanner cap
	probeWorkers = 16
)

// Scan walks the agent's subnets and finds whatever's out there. For each one: expand the
// CIDR, ping sweep to see who answers, then try the credential pool against the responders
// (snmp first, then a routeros login). We only ever send credential IDs back, not the secrets.
func (p *Poller) Scan(ctx context.Context, job proto.ScanJob) proto.ScanResult {
	var res proto.ScanResult
	for _, sn := range job.Subnets {
		if ctx.Err() != nil {
			break
		}
		res.Subnets = append(res.Subnets, proto.SubnetCandidates{
			SubnetID:   sn.SubnetID,
			Candidates: p.scanSubnet(ctx, sn.CIDR, job.Credentials),
		})
	}
	return res
}

func (p *Poller) scanSubnet(ctx context.Context, cidr string, creds proto.ScanCredentials) []proto.Candidate {
	hosts := expandCIDR(cidr, maxScanHosts)
	if len(hosts) == 0 {
		return nil
	}

	// 1. ping sweep, concurrently. collect whoever answers.
	reachable := make([]string, 0)
	var mu sync.Mutex
	sem := make(chan struct{}, pingWorkers)
	var wg sync.WaitGroup
	for _, ip := range hosts {
		if ctx.Err() != nil {
			break
		}
		wg.Add(1)
		sem <- struct{}{}
		go func(ip string) {
			defer wg.Done()
			defer func() { <-sem }()
			if ping(ip, pingTimeout) {
				mu.Lock()
				reachable = append(reachable, ip)
				mu.Unlock()
			}
		}(ip)
	}
	wg.Wait()

	// 2. now probe the ones that answered (bounded concurrency).
	cands := make([]proto.Candidate, len(reachable))
	psem := make(chan struct{}, probeWorkers)
	var pwg sync.WaitGroup
	for i, ip := range reachable {
		pwg.Add(1)
		psem <- struct{}{}
		go func(i int, ip string) {
			defer pwg.Done()
			defer func() { <-psem }()
			cands[i] = probeHost(ip, creds)
		}(i, ip)
	}
	pwg.Wait()
	return cands
}

// probeHost tries snmp first (its the common case), then falls back to a routeros login.
// We still return a candidate for a host that only answered ping (method ""), the operator
// might have a credential for it we don't know about.
func probeHost(ip string, creds proto.ScanCredentials) proto.Candidate {
	c := proto.Candidate{IP: ip}
	for _, sc := range creds.SNMP {
		if name, ok := snmpSysName(ip, sc.Community); ok {
			c.Sysname, c.Method, c.CredentialID = name, "snmp", sc.CredentialID
			return c
		}
	}
	for _, rc := range creds.RouterOS {
		if routerosLoginOK(ip, rc) {
			c.Method, c.CredentialID = "routeros", rc.CredentialID
			return c
		}
	}
	return c
}

func snmpSysName(ip, community string) (string, bool) {
	g := &gosnmp.GoSNMP{Target: ip, Port: 161, Community: community, Version: gosnmp.Version2c, Timeout: time.Second, Retries: 0}
	if g.Connect() != nil {
		return "", false
	}
	defer g.Conn.Close()
	res, err := g.Get([]string{oidSysName})
	if err != nil || len(res.Variables) == 0 || res.Variables[0].Type == gosnmp.NoSuchObject {
		return "", false
	}
	if b, ok := res.Variables[0].Value.([]byte); ok {
		return string(b), true
	}
	return fmt.Sprintf("%v", res.Variables[0].Value), true
}

func routerosLoginOK(ip string, rc proto.RouterOSCred) bool {
	port := rc.APIPort
	if port == 0 {
		port = 8728
	}
	c, err := routeros.DialTimeout(fmt.Sprintf("%s:%d", ip, port), rc.Username, rc.Password, 2*time.Second)
	if err != nil {
		return false
	}
	_ = c.Close()
	return true
}

// expandCIDR returns the usable host addresses in an IPv4 CIDR (excluding network +
// broadcast for prefixes < /31), capped at max.
func expandCIDR(cidr string, max int) []string {
	_, n, err := net.ParseCIDR(cidr)
	if err != nil || n.IP.To4() == nil {
		return nil
	}
	ones, bits := n.Mask.Size()
	if bits != 32 {
		return nil
	}
	start := binary.BigEndian.Uint32(n.IP.To4())
	count := uint32(1) << uint(bits-ones)

	var lo, hi uint32
	switch {
	case ones >= 31: // /31, /32 - every address is usable
		lo, hi = start, start+count-1
	default: // skip network + broadcast
		lo, hi = start+1, start+count-2
	}

	hosts := make([]string, 0)
	for ip := lo; ip <= hi && len(hosts) < max; ip++ {
		b := make([]byte, 4)
		binary.BigEndian.PutUint32(b, ip)
		hosts = append(hosts, net.IP(b).String())
	}
	return hosts
}
