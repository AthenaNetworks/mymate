<?php

namespace App\Models;

use Database\Factories\GraphFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved custom graph (GitHub #28): a name plus a `config` describing what to plot - the metric
 * (rate/util), the series (interface + direction) and whether to draw a combined total.
 */
class Graph extends Model
{
    /** @use HasFactory<GraphFactory> */
    use HasFactory;

    protected $fillable = ['name', 'config'];

    protected $casts = [
        'config' => 'array',
    ];
}
