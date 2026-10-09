<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasTenantRoles;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToBusiness, HasPublicId, HasApiTokens, HasTenantRoles, SoftDeletes, \Spatie\Activitylog\Traits\LogsActivity;

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return \Spatie\Activitylog\LogOptions::defaults()->logOnly(['is_active', 'must_change_pin', 'role_hint', 'failed_pin_attempts', 'locked_until', 'last_login_at'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public const PUBLIC_ID_PREFIX = 'usr_';
    protected $attributes = ['is_active' => 1, 'must_change_pin' => 1, 'failed_pin_attempts' => 0];
    protected $fillable = ['branch_id', 'name', 'phone', 'pin_hash', 'role_hint', 'is_active', 'must_change_pin'];
    protected $hidden = ['id', 'public_id', 'business_id', 'branch_id', 'phone', 'phone_lookup', 'pin_hash', 'failed_pin_attempts', 'locked_until', 'totp_secret', 'totp_confirmed_at', 'totp_last_step'];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('phone')) {
                $user->phone = \App\Support\StaffPhone::normalize($user->phone);
                $user->phone_lookup = static::phoneLookup($user->phone);
            }
        });
        static::updated(function (User $user): void {
            if ($user->wasChanged('is_active') && ! $user->is_active) { $user->revokeStaffSessions(); }
        });
        static::deleted(fn (User $user) => $user->revokeStaffSessions());
    }

    private function revokeStaffSessions(): void
    {
        $this->tokens()->delete();
        AuthChallenge::where('user_id', $this->id)->delete();
        SupervisorApproval::revokeFor($this);
    }

    public static function phoneLookup(string $phone): string
    {
        return hash_hmac('sha256', \App\Support\StaffPhone::normalize($phone), config('app.key'));
    }

    public function scopeForPhone($query, string $phone)
    {
        return $query->where('phone_lookup', static::phoneLookup($phone));
    }

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'phone' => 'encrypted', 'pin_hash' => 'hashed', 'is_active' => 'boolean',
            'must_change_pin' => 'boolean', 'failed_pin_attempts' => 'integer', 'locked_until' => 'datetime', 'last_login_at' => 'datetime',
            'totp_secret' => 'encrypted', 'totp_confirmed_at' => 'datetime', 'totp_last_step' => 'integer'];
    }

    public function getAuthPasswordName(): string
    {
        return 'pin_hash';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
