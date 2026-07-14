// Shared domain types mirroring the API resources (App\Http\Resources\*).

/** The signed-in operator. */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
}

/**
 * A row in the operator roster. The API shapes this by the *viewer\'s* tier -
 * an admin sees `email`/`created_at`, a normal operator gets only `id`/`name`/`is_admin`.
 */
export interface Operator {
    id: number;
    name: string;
    is_admin: boolean;
    email?: string;
    created_at?: string;
}

export type PollMethod = 'snmp' | 'routeros' | 'none';
export type DeviceStatus = 'up' | 'down' | 'unknown';
export type DeviceType = 'router' | 'switch' | 'ap' | 'server' | 'internet' | 'unknown';

export interface Device {
    id: number;
    name: string;
    mgmt_ip: string;
    poll_method: PollMethod;
    monitored: boolean; // false = polling paused (no throughput/metrics collected)
    status: DeviceStatus;
    last_change: string | null;
    map_x: number;
    map_y: number;
    credential_id: number | null;
    ssh_credential_id: number | null; // dedicated SSH cred for backups (separate from poll cred)
    agent_id: number | null;
    // NOC-console metadata.
    device_type: DeviceType;
    parent_device_id: number | null;
    parent_name: string | null;
    vendor: string | null;
    model: string | null;
    uptime_seconds: number | null;
    uptime_at: string | null;
    // Firmware upgrade tracking.
    os_version: string | null;
    latest_version: string | null;
    upgrade_status: UpgradeStatus | null;
    upgrade_message: string | null;
    upgrade_at: string | null;
    up_to_date: boolean;
    // Interface-discovery visibility.
    discovery_error: string | null;
    discovered_at: string | null;
    // Resource metrics (latest poll) - the map tile can show any of these. Null = not
    // read (unsupported OID / never polled), never treated as zero.
    cpu_pct: number | null;
    mem_used_pct: number | null;
    temp_c: number | null;
    metrics_at: string | null;
    signal_dbm: number | null;
    snr_db: number | null;
    ccq_pct: number | null;
    wireless_clients: number | null;
    rtt_ms: number | null;
    loss_pct: number | null;
    ping_at: string | null;
    // Config-backup mirror - last Rusted run, cached on the device.
    backup_enabled: boolean;
    backup_driver: string | null;
    backup_status: BackupStatus | null;
    backup_message: string | null;
    backup_at: string | null;
    backup_commit: string | null;
}

/** Outcome of the last config-backup run. `null` on a device = never backed up. */
export type BackupStatus = 'pending' | 'ok' | 'unchanged' | 'failed';

/** Backup still running -> show a spinner (mirrors UPGRADE_IN_PROGRESS). */
export const BACKUP_IN_PROGRESS: ReadonlySet<BackupStatus> = new Set(['pending']);

/**
 * Rusted backup drivers offered in the inspector (mirrors App\Support\RustedDrivers::ALL).
 * The label is what an operator sees; the value is the Rusted driver name.
 */
export const RUSTED_DRIVERS: { value: string; label: string }[] = [
    { value: 'mikrotik_routeros', label: 'MikroTik RouterOS' },
    { value: 'cisco_ios', label: 'Cisco IOS' },
    { value: 'cisco_nxos', label: 'Cisco Nexus (NX-OS)' },
    { value: 'cisco_asa', label: 'Cisco ASA' },
    { value: 'juniper_junos', label: 'Juniper Junos' },
    { value: 'arista_eos', label: 'Arista EOS' },
    { value: 'fortinet', label: 'Fortinet' },
    { value: 'vyos', label: 'VyOS' },
    { value: 'generic', label: 'Generic' },
];

/** One stored config version (a git commit of the device's config file). */
export interface BackupVersion {
    commit: string;
    date: string;
    subject: string;
}

/** Automatic-backup schedule (App\Support\BackupSchedule). */
export type BackupFrequency = 'hourly' | 'every_6h' | 'every_12h' | 'daily' | 'weekly';
export interface BackupScheduleConfig {
    enabled: boolean;
    frequency: BackupFrequency;
    hour: number;
    weekday: number;
    last_run_at: string | null;
}

/** One row of a device\'s backup history (proxied from Rusted). */
export interface BackupHistoryEntry {
    started_at: string | null;
    finished_at: string | null;
    status: string;
    message: string | null;
    bytes: number | null;
    commit: string | null;
}

export type UpgradeStatus =
    | 'queued'
    | 'checking'
    | 'downloading'
    | 'rebooting'
    | 'done'
    | 'up_to_date'
    | 'failed';

/** Upgrade still running -> show a spinner. */
export const UPGRADE_IN_PROGRESS: ReadonlySet<UpgradeStatus> = new Set([
    'queued',
    'checking',
    'downloading',
    'rebooting',
]);

export interface NetworkInterface {
    id: number;
    device_id: number;
    if_index: number;
    name: string;
    description: string | null;
    speed_mbps: number | null; // physical port capacity, read-only from SNMP
    util_in: number | null; // per-port utilisation % (vs speed_mbps) - inspector only
    util_out: number | null;
    bps_in: number | null; // latest raw throughput (bits/sec) - the live signal link util derives from
    bps_out: number | null;
}

export interface Link {
    id: number;
    a_device_id: number;
    // Interface ends are null for a ping-only device (no interfaces) - the link then
    // draws device-to-device and takes its throughput from whichever end has one.
    a_interface_id: number | null;
    b_device_id: number;
    b_interface_id: number | null;
    a_interface: NetworkInterface | null;
    b_interface: NetworkInterface | null;
    // Per-link bandwidth override + resolved effective speed per direction.
    // bw_* null = derive from the slowest end; eff_* is the value util% is computed against.
    bw_ab_mbps: number | null;
    bw_ba_mbps: number | null;
    eff_ab_mbps: number | null;
    eff_ba_mbps: number | null;
}

// Settings - editable engine tunables (mirrors Settings::effective()).
export interface EngineSetting {
    key: string;
    value: number;
    label: string;
    min: number;
    max: number;
}

// A credential for the Settings screen - secrets are never returned (CredentialResource).
export interface Credential {
    id: number;
    name: string;
    type: 'snmp' | 'routeros' | 'ssh';
    api_port: number;
    has_secret: boolean;
    device_count: number;
}

// Update check - this install's version vs the latest GitHub release.
export interface UpdateStatus {
    current: string;
    latest: string | null;
    update_available: boolean;
    url: string | null;
    checked_at: string;
}

// Remote agents / probes.
export interface Agent {
    id: number;
    name: string;
    status: 'enrolled' | 'online' | 'offline';
    last_seen_at: string | null;
    version: string | null;
    platform: string | null;
    device_count: number;
    subnet_count: number;
}

// Multiple maps. Named NetworkMap to avoid clashing with JS Map.
export interface NetworkMap {
    id: number;
    name: string;
    parent_map_id: number | null;
    is_default: boolean;
    position: number;
    device_count: number;
}

export interface MapPosition {
    device_id: number;
    x: number;
    y: number;
}

export interface InterMapLink {
    id: number;
    local_device_id: number;
    remote_device_id: number;
    remote_device_name: string | null;
    remote_map_id: number | null;
    remote_map_name: string | null;
    bps: number | null; // busiest throughput across the link (bits/sec)
    util: number | null; // busiest util% (null when no speed known)
    portal_x: number | null; // saved portal position on this map (null = auto-placed)
    portal_y: number | null;
}

export interface MapDetail {
    id: number;
    name: string;
    parent_map_id: number | null;
    positions: MapPosition[];
    inter_map_links: InterMapLink[];
}

// Alerting.
export type AlertConditionType =
    | 'device_down'
    | 'high_util'
    | 'upgrade_failed'
    | 'new_discovery'
    | 'backup_failed'
    | 'high_metric';

export type DeviceMetricKey = 'cpu' | 'mem' | 'temp' | 'latency' | 'loss';

/** Alert policy targeting - which devices a policy covers. */
export interface AlertScope {
    type: 'all' | 'device_type' | 'map' | 'devices';
    device_type?: DeviceType;
    map_id?: number;
    device_ids?: number[];
}

/** A scheduled maintenance window - suppresses alerts for its scope while active. */
export interface MaintenanceWindow {
    id: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    scope: AlertScope;
    enabled: boolean;
    active: boolean;
}

export interface AlertPolicy {
    id: number;
    name: string;
    condition: AlertConditionType;
    condition_label: string;
    params: { threshold?: number; duration_minutes?: number; suppress_dependent?: boolean; metric?: DeviceMetricKey };
    scope: AlertScope;
    enabled: boolean;
    transport_ids: number[];
}

export type TransportType = 'email' | 'slack' | 'teams' | 'messenger' | 'webhook' | 'discord' | 'telegram' | 'pagerduty';

export interface AlertTransport {
    id: number;
    name: string;
    type: TransportType;
    enabled: boolean;
}

export interface AlertEvent {
    id: number;
    policy_id: number;
    policy_name: string | null;
    condition: string | null;
    status: 'firing' | 'resolved';
    message: string;
    delivered: boolean;
    fired_at: string;
    resolved_at: string | null;
    acknowledged_at: string | null;
    acknowledged_by_name: string | null;
}

// Outage timeline - mirrors OutageResource.
export interface Outage {
    id: number;
    device_id: number;
    device_name: string | null;
    started_at: string;
    ended_at: string | null;
    duration_s: number | null;
    ongoing: boolean;
    cause: string | null;
}

// Live throughput event (App\Events\InterfaceUtilUpdated) - coalesced across devices.
export interface InterfaceUtilFrame {
    interface_id: number;
    device_id: number;
    util_in: number | null;
    util_out: number | null;
    speed_mbps: number | null;
    bps_in: number | null;
    bps_out: number | null;
    status: DeviceStatus;
}

export interface InterfaceUtilUpdatedPayload {
    devices: { device_id: number; status: DeviceStatus; interfaces: InterfaceUtilFrame[] }[];
    device_count: number;
    interface_count: number;
}

// Recent history - a bucketed sample point from the history endpoint.
export interface InterfaceSample {
    ts: string;
    util_in: number | null;
    util_out: number | null;
    bps_in: number | null;
    bps_out: number | null;
}

// Which resource a device's map tile shows. 'throughput' = busiest-interface util
// (the default, unchanged); the rest come from the device-metrics pipeline.
export type TileMetric = 'throughput' | 'cpu' | 'mem' | 'temp';

// Live device-metrics event (App\Events\DeviceMetricsUpdated) - coalesced across devices.
export interface DeviceMetricsFrame {
    device_id: number;
    cpu_pct: number | null;
    mem_used_pct: number | null;
    temp_c: number | null;
    signal_dbm: number | null;
    snr_db: number | null;
    ccq_pct: number | null;
    wireless_clients: number | null;
}

export interface DeviceMetricsUpdatedPayload {
    devices: DeviceMetricsFrame[];
    device_count: number;
}

// Recent history - a bucketed cpu/mem/temp point from the device metric-samples endpoint.
export interface DeviceMetricSample {
    ts: string;
    cpu_pct: number | null;
    mem_used_pct: number | null;
    temp_c: number | null;
    signal_dbm: number | null;
    snr_db: number | null;
    ccq_pct: number | null;
    wireless_clients: number | null;
}

export interface DevicePingSample {
    ts: string;
    rtt_ms: number | null;
    loss_pct: number | null;
    jitter_ms: number | null;
}

// Auto-discovery - mirrors SubnetResource / DiscoveryCandidateResource.
export type DiscoveryStatus = 'new' | 'approved' | 'ignored';

export interface Subnet {
    id: number;
    cidr: string;
    label: string | null;
    enabled: boolean;
    scan_interval_s: number;
    last_scanned_at: string | null;
    agent_id: number | null; // scanned by a remote agent when set, else centrally
}

export interface DiscoveryCandidate {
    id: number;
    ip: string;
    status: DiscoveryStatus;
    sysname: string | null;
    detected_method: PollMethod | null;
    matched_credential_id: number | null;
    first_seen: string | null;
    last_seen: string | null;
}
