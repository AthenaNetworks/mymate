<?php

namespace App\Enums;

/**
 * Coarse device role for the topology node badge (NET/RTR/SW/AP/SRV). Auto-inferred
 * best-effort by CaptureDeviceFacts; operator-overridable. `internet` marks a manual
 * upstream/cloud node. Stored as a plain string (enum cast on the model).
 */
enum DeviceType: string
{
    case Router = 'router';
    case Switch = 'switch';
    case Ap = 'ap';
    case Server = 'server';
    case Internet = 'internet';
    case Unknown = 'unknown';
}
