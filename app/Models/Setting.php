<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single operator override for an engine tunable. Read through the
 * `App\Support\Settings` repository, which falls back to `config/mymate.php`.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type'];

    protected $casts = [
        'value' => 'json', // scalars survive the round-trip (5 -> "5" -> 5)
    ];
}
