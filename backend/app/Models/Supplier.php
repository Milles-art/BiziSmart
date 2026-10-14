<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class Supplier extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='sup_';
    protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['name'=>'encrypted','phone'=>'encrypted','address'=>'encrypted','is_active'=>'boolean','revision'=>'integer'];}
    protected static function booted():void {static::deleting(fn()=>throw new \LogicException('Supplier history must be retained.'));}
}
