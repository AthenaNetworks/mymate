<?php

namespace App\Services\RouterOs;

use RuntimeException;

/**
 * RouterOS API failure (connect timeout on a filtered port, bad credentials,
 * transport error, missing credential). Messages carry the host + error only -
 * never the username/password.
 */
class RouterOsClientException extends RuntimeException {}
