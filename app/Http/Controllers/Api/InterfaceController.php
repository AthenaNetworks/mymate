<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterfaceResource;
use App\Models\Device;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InterfaceController extends Controller
{
    /** A device's interfaces - used by the link binder to pick each end. */
    public function index(Device $device): AnonymousResourceCollection
    {
        return InterfaceResource::collection($device->interfaces()->orderBy('if_index')->get());
    }
}
