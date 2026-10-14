<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;
class DebtAllocation extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'dalloc_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','customer_id','customer_payment_id','sale_debt_id'];
    protected function casts(): array { return ['amount'=>'integer']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Debt allocations are append-only.'));
        static::deleting(fn () => throw new \LogicException('Debt allocations are append-only.'));
    }
}
