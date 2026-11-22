<?php

namespace App\Actions\Alerts;

use RuntimeException;

/**
 * A transport refused the alert for a reason the operator can act on (a bad token, a lapsed
 * account). Its message is written by us and holds no secret, so the Test button can show it
 * instead of a bare "Delivery failed".
 */
class TransportRejected extends RuntimeException {}
