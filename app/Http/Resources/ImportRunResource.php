<?php

namespace App\Http\Resources;

use App\Models\ImportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportRun */
class ImportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mode' => $this->mode->value,
            'include_history' => $this->include_history,
            'extract_timeout' => $this->extract_timeout,
            'status' => $this->status->value,
            'stage' => $this->stage,
            'progress' => $this->progress,
            'cancel_requested' => $this->cancel_requested,
            'summary' => $this->summary,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
