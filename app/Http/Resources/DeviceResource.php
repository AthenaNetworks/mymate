<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mgmt_ip' => $this->mgmt_ip,
            'poll_method' => $this->poll_method->value,
            'monitored' => (bool) $this->monitored,
            'status' => $this->status->value,
            'last_change' => $this->last_change,
            'map_x' => $this->map_x,
            'map_y' => $this->map_y,
            'credential_id' => $this->credential_id,
            'ssh_credential_id' => $this->ssh_credential_id,
            'agent_id' => $this->agent_id,
            // NOC-console metadata.
            'device_type' => $this->device_type?->value ?? 'unknown',
            'parent_device_id' => $this->parent_device_id,
            'parent_name' => $this->parent?->name,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'uptime_seconds' => $this->uptime_seconds,
            'uptime_at' => $this->uptime_at,
            // Firmware upgrade tracking.
            'os_version' => $this->os_version,
            'latest_version' => $this->latest_version,
            'upgrade_status' => $this->upgrade_status?->value,
            'upgrade_message' => $this->upgrade_message,
            'upgrade_at' => $this->upgrade_at,
            'up_to_date' => $this->os_version !== null
                && $this->latest_version !== null
                && $this->os_version === $this->latest_version,
            // Interface-discovery visibility.
            'discovery_error' => $this->discovery_error,
            'discovered_at' => $this->discovered_at,
            // Resource metrics (latest poll) - the map tile can show any of these.
            'cpu_pct' => $this->cpu_pct,
            'mem_used_pct' => $this->mem_used_pct,
            'temp_c' => $this->temp_c,
            'metrics_at' => $this->metrics_at,
            'rtt_ms' => $this->rtt_ms,
            'loss_pct' => $this->loss_pct,
            'ping_at' => $this->ping_at,
            // Config-backup mirror - last run cached from Rusted.
            'backup_enabled' => (bool) $this->backup_enabled,
            'backup_driver' => $this->backup_driver,
            'backup_status' => $this->backup_status?->value,
            'backup_message' => $this->backup_message,
            'backup_at' => $this->backup_at,
            'backup_commit' => $this->backup_commit,
        ];
    }
}
