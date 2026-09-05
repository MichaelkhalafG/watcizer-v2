<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The SHARED `users` table (D5: accounts are shared across storefronts and with the
 * legacy app). Column names follow the legacy schema: first_name, last_name, type.
 *
 * No factory on purpose: this table holds real customer accounts in every environment,
 * so nothing in the core app may fabricate rows in it. The core roles (admin | data-entry)
 * are a dedicated mechanism decided in wave 4 (AGENTS.md §2.18), never derived from `type`.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'type', 'phone_number', 'image'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_reengagement_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
