<?php

namespace App\Models;

use Database\Factories\RouterosPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached RouterOS upgrade package (.npk): the main `routeros` one or an extra such as
 * `wifi-qcom-ac`, for a version + arch. `token` gates the unauthenticated serving URL that
 * routers fetch from.
 */
class RouterosPackage extends Model
{
    /** @use HasFactory<RouterosPackageFactory> */
    use HasFactory;

    protected $fillable = ['name', 'version', 'arch', 'channel', 'status', 'size_bytes', 'path', 'token', 'error', 'fetched_at'];

    protected $casts = [
        'size_bytes' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
