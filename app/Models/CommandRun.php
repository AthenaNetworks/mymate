<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'template_id', 'command', 'command_hash', 'timeout_seconds',
        'target_count', 'status', 'confirmed_at', 'completed_at',
    ];

    protected $casts = [
        'timeout_seconds' => 'integer',
        'target_count' => 'integer',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $hidden = ['command'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommandTemplate::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CommandRunTarget::class, 'run_id');
    }
}
