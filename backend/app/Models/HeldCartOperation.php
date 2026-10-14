<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class HeldCartOperation extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='hop_';
    public $timestamps=false;
    protected $guarded=['id','business_id','public_id'];
    protected function casts(): array { return ['result'=>'encrypted:array']; }
    protected static function booted(): void {
        static::updating(fn()=>throw new \LogicException('Held cart operations are immutable.'));
        static::deleting(fn()=>throw new \LogicException('Held cart operations cannot be deleted.'));
    }
}
