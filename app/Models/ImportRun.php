<?php

namespace App\Models;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One Dude-database import. The controller creates it (pending), the job moves it
 * through extracting -> importing -> completed/failed and writes the `summary`.
 *
 * @property ImportStatus $status
 * @property ImportMode $mode
 */
class ImportRun extends Model
{
    protected $fillable = [
        'original_filename', 'stored_path', 'mode', 'include_history',
        'status', 'stage', 'progress', 'cancel_requested',
        'summary', 'error', 'user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'mode' => ImportMode::class,
        'status' => ImportStatus::class,
        'include_history' => 'boolean',
        'cancel_requested' => 'boolean',
        'progress' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** The uploaded .db path is server-internal - never expose it to the API. */
    protected $hidden = ['stored_path'];

    public function markStatus(ImportStatus $status): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}
