<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An operator account. Auth is Sanctum SPA (stateful cookie session);
 * `HasApiTokens` is kept for optional personal access tokens.  adds a single
 * `is_admin` tier: admins manage operator accounts, normal operators are view-only.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // NOTE: `is_admin` is deliberately NOT fillable - the admin tier is a privilege, so
    // it's set explicitly in the controller *after* the admin gate, never from a mass-
    // assigned payload. An operator cannot self-escalate by smuggling it into a request.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // auto-hash on set
        'is_admin' => 'boolean',
    ];

    /** Admins can manage operator accounts; normal operators are view-only. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
