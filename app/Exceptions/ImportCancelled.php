<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operator cancels an in-flight Dude import. Inside the config
 * transaction this rolls everything back (clean); during history it just stops the
 * append loop (partial samples are valid, non-corrupting rows).
 */
class ImportCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Import cancelled by operator.');
    }
}
