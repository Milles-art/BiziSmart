<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class SupervisorApproval extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const PUBLIC_ID_PREFIX = 'apr_';
    protected $guarded = ['id', 'business_id'];
    protected $hidden = ['id', 'business_id', 'requester_id', 'approver_id', 'requester_token_id', 'device_id', 'token_hash', 'payload_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'consumed_at' => 'datetime']; }

    public static function revokeFor(User $user): void
    {
        $user->assertCurrentBusiness();
        static::where(fn ($query) => $query->where('requester_id', $user->id)->orWhere('approver_id', $user->id))->delete();
    }
}
