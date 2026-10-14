<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use BelongsToBusiness, HasPublicId, \Spatie\Activitylog\Traits\LogsActivity;

    public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions
    {
        return \Spatie\Activitylog\LogOptions::defaults()->logOnly(['status', 'user_id', 'approved_by'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public const PUBLIC_ID_PREFIX = 'dev_';
    protected $attributes = ['status' => 'pending'];
    protected $fillable = ['user_id', 'device_uid', 'name', 'status', 'approved_by', 'last_seen_at', 'last_ip'];
    protected $hidden = ['id', 'public_id', 'business_id', 'user_id', 'approved_by', 'last_ip'];

    protected static function booted(): void
    {
        static::updated(function (Device $device): void {
            if ($device->wasChanged('status') && $device->status !== 'approved') {
                OfflineLease::where('device_id',$device->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
            }
        });
    }

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'last_seen_at' => 'datetime', 'last_ip' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
