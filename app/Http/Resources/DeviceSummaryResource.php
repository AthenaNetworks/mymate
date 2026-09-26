<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lean device row (`GET /devices?fields=summary`) for pickers and name lookups: enough to
 * label, filter and pick a device, none of the metrics / backup / upgrade detail DeviceResource
 * carries. Field names match DeviceResource so the SPA can treat it as a subset.
 */
class DeviceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mgmt_ip' => $this->mgmt_ip,
            'status' => $this->status->value,
            'monitored' => (bool) $this->monitored,
            'poll_method' => $this->poll_method->value,
            'device_type' => $this->device_type?->value ?? 'unknown',
            'vendor' => $this->vendor,
            'model' => $this->model,
            'parent_device_id' => $this->parent_device_id,
            'parent_name' => $this->parent?->name,
            'maps_count' => $this->whenCounted('mapPositions'),
        ];
    }
}
