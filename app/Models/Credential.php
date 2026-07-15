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
        'snmp_version', 'snmp_sec_name', 'snmp_sec_level',
        'snmp_auth_protocol', 'snmp_auth_passphrase', 'snmp_priv_protocol', 'snmp_priv_passphrase',
    ];

    protected $casts = [
        'snmp_community' => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
        'private_key' => 'encrypted',
        // v3 auth/priv passphrases are secrets - encrypted at rest like every other credential.
        'snmp_auth_passphrase' => 'encrypted',
        'snmp_priv_passphrase' => 'encrypted',
        'api_port' => 'integer',
    ];

    // Never expose secrets when serialised to JSON (e.g. via API resources).
    protected $hidden = [
        'snmp_community', 'username', 'password', 'private_key',
        'snmp_auth_passphrase', 'snmp_priv_passphrase',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
