<?php

namespace App\Services\Snmp;

use RuntimeException;

/**
 * SNMP failure (timeout, unreachable, no community). Named to avoid colliding
 * with PHP's built-in \SNMPException (class names are case-insensitive, so
 * "SnmpException" and "SNMPException" would resolve to the same symbol).
 *
 * Messages must never contain the community string - only host + transport error.
 */
class SnmpClientException extends RuntimeException {}
