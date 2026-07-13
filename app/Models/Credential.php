<?php

namespace App\Models;

use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'snmp_community', 'username', 'password', 'private_key', 'api_port',
    ];

    protected $casts = [
        'snmp_community' => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
        'private_key' => 'encrypted',
        'api_port' => 'integer',
    ];

    // Never expose secrets when serialised to JSON (e.g. via API resources).
    protected $hidden = ['snmp_community', 'username', 'password', 'private_key'];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
