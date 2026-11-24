<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if (!in_array($user->status ?? 'active', ['active', 'suspended'], true)) {
                throw ValidationException::withMessages(['status' => 'Account status must be active or suspended.']);
            }

            $superRoleId = Role::where('code', 'SUPER_ADMIN')->value('id');
            if (!$superRoleId) return;

            $wasSuper = $user->exists && (int) $user->getRawOriginal('role_id') === (int) $superRoleId;
            $isSuper = (int) $user->role_id === (int) $superRoleId;
            if ($wasSuper && (!$isSuper || $user->status !== 'active')) {
                throw ValidationException::withMessages(['role_id' => 'The super administrator cannot be suspended or demoted.']);
            }
            if ($isSuper && !$wasSuper && ($user->exists || static::where('role_id', $superRoleId)->exists())) {
                throw ValidationException::withMessages(['role_id' => 'Only the existing super administrator may hold this role.']);
            }
        });

        static::deleting(function (User $user) {
            if ($user->role?->code === 'SUPER_ADMIN') {
                throw ValidationException::withMessages(['user' => 'The super administrator cannot be deleted.']);
            }
        });
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected $fillable = [
        'staff_id',
        'name',
        'email',
        'username',
        'password',
        'role_id',
        'department_id',
        'status',
        'is_first_login',
        'designation',
        'grade_level',
        'last_login_at',
        'last_login_ip'
    ];

    protected $casts = [
        'is_first_login' => 'boolean',
    ];

    protected $hidden = ['password', 'remember_token', 'super_admin_guard'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
