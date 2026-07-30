<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backhaul link between two sites (see the create_site_links_table migration). Imported from
 * the OSS that owns the backbone topology so the geo map draws real tower-to-tower links.
 */
class SiteLink extends Model
{
    protected $fillable = ['site_a_id', 'site_b_id', 'media_type', 'external_ref'];

    public function siteA(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_a_id');
    }

    public function siteB(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_b_id');
    }
}
