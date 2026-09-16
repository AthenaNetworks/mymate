<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommandTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'command', 'timeout_seconds', 'enabled', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'timeout_seconds' => 'integer',
        'enabled' => 'boolean',
    ];

    protected $hidden = ['command'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function commandHash(): string
    {
        return hash('sha256', $this->command);
    }

    public function toApiArray(bool $includeCommand = true): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'timeout' => $this->timeout_seconds,
            'enabled' => $this->enabled,
            'command_hash' => $this->commandHash(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeCommand) {
            $data['command'] = $this->command;
        }

        return $data;
    }
}
