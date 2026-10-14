<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SaleReturnItem extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX = 'ri_';
    public const UPDATED_AT = null;
    protected $guarded = ['id','business_id','public_id'];
    protected $hidden = ['id','business_id','sale_id','sale_return_id','sale_item_id','product_id','batch_id','qty_atoms'];
    protected function casts(): array { return ['qty_atoms'=>'integer']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Return journals are append-only.'));
        static::deleting(fn()=>throw new \LogicException('Return journals are append-only.'));
    }
}
