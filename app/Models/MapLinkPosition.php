<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saved canvas position of an inter-map link portal on a given map. */
class MapLinkPosition extends Model
{
    protected $fillable = ['map_id', 'link_id', 'x', 'y'];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
    ];
}
