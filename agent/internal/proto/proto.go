package proto

import "encoding/json"

// The WebSocket wire protocol (JSON). The agent dials out and authenticates with a Bearer
// token on the handshake; thereafter both sides exchange these envelopes. The central app's
// agent hub (server side) implements the matching end.

// Outbound: agent -> server.

// Hello is the first message the agent sends after connecting.
type Hello struct {
	Type     string `json:"type"` // "hello"
	Version  string `json:"version"`
	Platform string `json:"platform"`
	Name     string `json:"name"`
}

// Result reports the outcome of a batch of jobs back to the server. Payload shape is
// finalised alongside the pollers; RawMessage keeps this envelope stable.
type Result struct {
	Type    string          `json:"type"` // "result"
	Payload json.RawMessage `json:"payload"`
}

// Inbound: server -> agent. Type selects how Payload is interpreted:
//
//	"poll" - a batch of devices to ping/SNMP/RouterOS-poll now
//	"scan" - a subnet to discover
//	"bye"  - the server is closing the session (e.g. token revoked)
type Inbound struct {
	Type    string          `json:"type"`
	Payload json.RawMessage `json:"payload"`
}

// --- Poll job (server -> agent, type "poll") ------------------------------

// PollJob is the "poll" payload: ping everything, SNMP-poll devices with a community,
// RouterOS-poll devices with an API login.
type PollJob struct {
	Ping     []PingTarget     `json:"ping"`
	SNMP     []SNMPTarget     `json:"snmp"`
	RouterOS []RouterOSTarget `json:"routeros"`
	Probes   []ProbeTarget    `json:"probes,omitempty"`
}

// ProbeTarget is one HTTP/TCP service probe (#19) for the agent to run from its own network (#33).
// The agent runs the check and reports the verdict; the server owns status/dampening/alerting.
type ProbeTarget struct {
	ProbeID      int    `json:"probe_id"`
	DeviceID     int    `json:"device_id"`
	Kind         string `json:"kind"` // "http" | "tcp"
	TimeoutMs    int    `json:"timeout_ms"`
	URL          string `json:"url,omitempty"`
	Method       string `json:"method,omitempty"`
	ExpectStatus string `json:"expect_status,omitempty"`
	ExpectBody   string `json:"expect_body,omitempty"`
	VerifyTLS    bool   `json:"verify_tls,omitempty"`
	Host         string `json:"host,omitempty"` // TCP; defaults to the device mgmt IP server-side
	Port         int    `json:"port,omitempty"`
}

type PingTarget struct {
	DeviceID int    `json:"device_id"`
	IP       string `json:"ip"`
}

type SNMPTarget struct {
	DeviceID   int           `json:"device_id"`
	IP         string        `json:"ip"`
	Community  string        `json:"community"`
	SNMP       SNMPAuth      `json:"snmp,omitempty"` // v3 USM params when Version=="3"
	Interfaces []IfaceTarget `json:"interfaces"`
	// Metrics OIDs to read for cpu/mem/temp; nil when the device has no metrics profile.
	Metrics *MetricsTarget `json:"metrics,omitempty"`
	// Discover: also walk the ifTable (to find interfaces) and the standard facts OIDs
	// (sysDescr/sysLocation/ENTITY-MIB/uptime/memory) this cycle. Set on the discovery cadence,
	// so the agent-polled device is (re)discovered from the agent, not the central server (#33).
	Discover bool `json:"discover,omitempty"`
}

// SNMPAuth carries the version + v3 USM parameters. Empty/"2c" version means a plain community
// GET (Community on the enclosing target). Mirrors the server's SnmpCredential value object.
type SNMPAuth struct {
	Version        string `json:"version,omitempty"`  // "1" | "2c" | "3"
	SecName        string `json:"sec_name,omitempty"` // v3 USM user
	SecLevel       string `json:"sec_level,omitempty"` // noAuthNoPriv | authNoPriv | authPriv
	AuthProtocol   string `json:"auth_protocol,omitempty"`
	AuthPassphrase string `json:"auth_passphrase,omitempty"`
	PrivProtocol   string `json:"priv_protocol,omitempty"`
	PrivPassphrase string `json:"priv_passphrase,omitempty"`
}

// MetricsTarget describes how to read cpu/mem/temp for one device, driven by the server's
// per-vendor OID profile so vendor differences stay in one place (the server config). The agent
// executes these generically - it holds no vendor knowledge of its own.
type MetricsTarget struct {
	CPUWalk     string   `json:"cpu_walk,omitempty"`  // walk, average numeric values (hrProcessorLoad)
	CPUOids     []string `json:"cpu_oids,omitempty"`  // else GET each, take the first numeric
	Mem         string   `json:"mem,omitempty"`       // "hrstorage" | "cisco" | ""
	MemUsedWalk string   `json:"mem_used_walk,omitempty"` // cisco pools
	MemFreeWalk string   `json:"mem_free_walk,omitempty"`
	HrDescr     string   `json:"hr_descr,omitempty"` // hrStorage table columns
	HrSize      string   `json:"hr_size,omitempty"`
	HrUsed      string   `json:"hr_used,omitempty"`
	TempWalk    string   `json:"temp_walk,omitempty"`
	TempOids    []string `json:"temp_oids,omitempty"`
	TempDivisor int      `json:"temp_divisor,omitempty"`
}

type RouterOSTarget struct {
	DeviceID   int           `json:"device_id"`
	IP         string        `json:"ip"`
	Username   string        `json:"username"`
	Password   string        `json:"password"`
	APIPort    int           `json:"api_port"`
	Interfaces []IfaceTarget `json:"interfaces"`
}

// IfaceTarget carries what each poller needs: if_index for SNMP, name for RouterOS.
type IfaceTarget struct {
	InterfaceID int    `json:"interface_id"`
	IfIndex     int    `json:"if_index,omitempty"`
	Name        string `json:"name,omitempty"`
}

// --- Scan job (server -> agent, type "scan") ------------------------------

// ScanJob asks the agent to discover devices on its local subnets, trying the credential
// pool against each responder (the agent probes; only IDs come back, no secrets).
type ScanJob struct {
	Subnets     []ScanSubnet    `json:"subnets"`
	Credentials ScanCredentials `json:"credentials"`
}

type ScanSubnet struct {
	SubnetID int    `json:"subnet_id"`
	CIDR     string `json:"cidr"`
}

type ScanCredentials struct {
	SNMP     []SNMPCred     `json:"snmp"`
	RouterOS []RouterOSCred `json:"routeros"`
}

type SNMPCred struct {
	CredentialID int      `json:"credential_id"`
	Community    string   `json:"community"`
	SNMP         SNMPAuth `json:"snmp,omitempty"` // v3 USM params when Version=="3"
}

type RouterOSCred struct {
	CredentialID int    `json:"credential_id"`
	Username     string `json:"username"`
	Password     string `json:"password"`
	APIPort      int    `json:"api_port"`
}

// ScanStart (agent -> server, type "scan_start"): a subnet sweep has begun. Lets the server
// light up the "scanning" state + progress bar live, instead of only learning at the end.
type ScanStart struct {
	Type     string `json:"type"` // "scan_start"
	SubnetID int    `json:"subnet_id"`
	Total    int    `json:"total"` // usable hosts to sweep
}

// ScanProgress (agent -> server, type "scan_progress"): live counters during a subnet sweep.
type ScanProgress struct {
	Type     string `json:"type"` // "scan_progress"
	SubnetID int    `json:"subnet_id"`
	Swept    int    `json:"swept"` // hosts pinged so far
	Total    int    `json:"total"`
	Found    int    `json:"found"` // responders identified so far
}

// ScanResult (agent -> server, type "discovery"): what the scan found per subnet.
type ScanResult struct {
	Subnets []SubnetCandidates `json:"subnets"`
}

type SubnetCandidates struct {
	SubnetID   int         `json:"subnet_id"`
	Candidates []Candidate `json:"candidates"`
}

type Candidate struct {
	IP           string `json:"ip"`
	Sysname      string `json:"sysname,omitempty"`
	Method       string `json:"method,omitempty"`        // "snmp" | "routeros" | "" (responded, unidentified)
	CredentialID int    `json:"credential_id,omitempty"` // the pool credential that matched
}

// --- Result (agent -> server, type "result"/"discovery") ------------------
// Shape matches the server's IngestAgentResults / IngestAgentScan.

type ResultPayload struct {
	Pings      []PingResult      `json:"pings"`
	Throughput []FlowResult      `json:"throughput"`
	Metrics    []MetricsResult   `json:"metrics,omitempty"`
	Discovery  []DeviceDiscovery `json:"discovery,omitempty"`
	Probes     []ProbeCheck      `json:"probes,omitempty"`
}

// ProbeCheck is the outcome of one service probe the agent ran. LatencyMs/CertExpires are pointers
// so "not measured" stays distinct from zero. The server folds this into the probe row (#33).
type ProbeCheck struct {
	ProbeID     int      `json:"probe_id"`
	Up          bool     `json:"up"`
	LatencyMs   *float64 `json:"latency_ms"`
	Message     string   `json:"message,omitempty"`
	CertExpires *int64   `json:"cert_expires,omitempty"` // unix seconds, HTTPS only
}

// DeviceDiscovery is what a Discover pass found for one device: the interfaces walked from its
// ifTable, plus raw facts. The server parses the facts (vendor/model/serial derivation stays in
// one place, PHP) - the agent only walks the standard OIDs.
type DeviceDiscovery struct {
	DeviceID   int               `json:"device_id"`
	Interfaces []DiscoveredIface `json:"interfaces"`
	Facts      *DeviceFacts      `json:"facts,omitempty"`
}

// DiscoveredIface is one row of the ifTable/ifXTable. OperUp is a pointer so "not reported"
// stays distinct from down.
type DiscoveredIface struct {
	IfIndex   int    `json:"if_index"`
	Name      string `json:"name,omitempty"`  // ifName, else ifDescr
	Descr     string `json:"descr,omitempty"` // ifDescr
	SpeedMbps int    `json:"speed_mbps,omitempty"`
	OperUp    *bool  `json:"oper_up"`
}

// DeviceFacts is the raw SNMP facts the server needs to derive vendor/model/serial/geo. Kept raw
// on purpose so all the vendor-specific parsing lives server-side (CaptureDeviceFacts).
type DeviceFacts struct {
	SysDescr    string   `json:"sys_descr,omitempty"`
	SysLocation string   `json:"sys_location,omitempty"`
	UptimeTicks *uint64  `json:"uptime_ticks,omitempty"`
	MemKb       *uint64  `json:"mem_kb,omitempty"`
	EntModels   []string `json:"ent_models,omitempty"`
	EntSerials  []string `json:"ent_serials,omitempty"`
}

// MetricsResult is one device's cpu/mem/temp reading. Each field is a pointer so an
// unread metric marshals as null (not 0) and the server stores it as "not reported".
type MetricsResult struct {
	DeviceID   int      `json:"device_id"`
	CPUPct     *float64 `json:"cpu_pct"`
	MemUsedPct *float64 `json:"mem_used_pct"`
	TempC      *float64 `json:"temp_c"`
}

type PingResult struct {
	DeviceID int  `json:"device_id"`
	Up       bool `json:"up"`
}

type FlowResult struct {
	InterfaceID int     `json:"interface_id"`
	InBps       float64 `json:"in_bps"`
	OutBps      float64 `json:"out_bps"`
}
