<?php

namespace App\Actions\Discovery;

use RuntimeException;

/** A CMR candidate's address already belongs to a different CMR device (both behind one NAT). */
class CmrAddressTaken extends RuntimeException {}
