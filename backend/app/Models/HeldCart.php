<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class HeldCart extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='hold_';
    protected $guarded=['id','business_id','public_id'];
    protected function casts(): array { return ['draft'=>'encrypted:array','expires_at'=>'immutable_datetime']; }
    protected static function booted(): void {
        static::updating(function (HeldCart $cart) {
            foreach (['branch_id','actor_id','device_id','draft','expires_at'] as $field) {
                if ($cart->isDirty($field)) throw new \LogicException('Held cart drafts are immutable.');
            }
            if ($cart->getOriginal('status') !== 'held' || ! in_array($cart->status,['resumed','deleted'],true)) throw new \LogicException('Held cart handoff is single-use.');
        });
        static::deleting(fn()=>throw new \LogicException('Held carts retain their handoff history.'));
    }
}
