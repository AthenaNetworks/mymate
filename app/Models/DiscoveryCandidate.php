<?php

namespace App\Models;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use Database\Factories\DiscoveryCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host the scanner found (the review queue). Never auto-promoted: an operator
 * approves it (-> a `device` carrying `matched_credential` + `detected_method`) or
 * ignores it. `detected_method` is null when the host responded but matched no
 * credential / couldn't be identified.
 */
class DiscoveryCandidate extends Model
{
    /** @use HasFactory<DiscoveryCandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'ip', 'status', 'sysname', 'detected_method', 'matched_credential_id', 'first_seen', 'last_seen',
    ];

    protected $casts = [
        'status' => DiscoveryStatus::class,
        'detected_method' => PollMethod::class, // nullable - null cast stays null
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'new',
    ];

    public function matchedCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'matched_credential_id');
    }
}
