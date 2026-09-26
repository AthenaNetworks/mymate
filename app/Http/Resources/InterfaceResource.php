<?php

namespace App\Http\Resources;

use App\Models\NetworkInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NetworkInterface */
class InterfaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'if_index' => $this->if_index,
            'name' => $this->name,
            'description' => $this->description,
            'oper_status' => $this->oper_status, // up | down | null (not reported yet)
            'speed_mbps' => $this->speed_mbps, // read-only from SNMP
            'ospf_cost' => $this->ospf_cost, // OSPF outbound metric (RouterOS API), null if not OSPF
            'util_in' => $this->util_in,
            'util_out' => $this->util_out,
            'bps_in' => $this->bps_in,
            'bps_out' => $this->bps_out,
            // SFP / fibre optical power (dBm), null when there's no module or it can't be read.
            'optical_rx_dbm' => $this->optical_rx_dbm,
            'optical_tx_dbm' => $this->optical_tx_dbm,
            'optical_at' => $this->optical_at,
            // Port rates per second from the last counter read, null until two reads have landed.
            'pkts_in' => $this->pkts_in,
            'pkts_out' => $this->pkts_out,
            'errors_in' => $this->errors_in,
            'errors_out' => $this->errors_out,
            'discards_in' => $this->discards_in,
            'discards_out' => $this->discards_out,
        ];
    }
}
