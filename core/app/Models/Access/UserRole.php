<?php

namespace App\Models\Access;

use App\Domain\Access\Role;
use App\Models\Storefront\Storefront;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grant: this user holds this role, on this storefront or on all of them (M1g).
 *
 * @property int $id
 * @property int $user_id
 * @property string $role
 * @property int|null $storefront_id
 * @property int|null $granted_by
 */
class UserRole extends Model
{
    protected $table = 'core_user_roles';

    /** @var list<string> */
    protected $fillable = ['user_id', 'role', 'storefront_id', 'granted_by'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function asRole(): ?Role
    {
        return Role::tryFromValue($this->role);
    }
}
