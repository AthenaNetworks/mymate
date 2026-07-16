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
}

type PingTarget struct {
	DeviceID int    `json:"device_id"`
	IP       string `json:"ip"`
}

type SNMPTarget struct {
	DeviceID   int           `json:"device_id"`
	IP         string        `json:"ip"`
	Community  string        `json:"community"`
	Interfaces []IfaceTarget `json:"interfaces"`
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
	CredentialID int    `json:"credential_id"`
	Community    string `json:"community"`
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
	Pings      []PingResult `json:"pings"`
	Throughput []FlowResult `json:"throughput"`
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
