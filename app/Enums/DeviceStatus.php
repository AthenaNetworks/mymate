<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Unknown = 'unknown';
}
