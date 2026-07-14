<?php

namespace App\Http\Resources;

use App\Models\Sensor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sensor */
class SensorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'oid' => $this->oid,
            'unit' => $this->unit,
            'divisor' => $this->divisor,
            'scope' => $this->scope ?? ['type' => 'all'],
            'enabled' => $this->enabled,
        ];
    }
}
