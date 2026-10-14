<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SaleExchange extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX = 'exch_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','sale_return_id','replacement_sale_id'];
    protected function casts(): array { return []; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Return journals are append-only.'));
        static::deleting(fn()=>throw new \LogicException('Return journals are append-only.'));
    }
}
