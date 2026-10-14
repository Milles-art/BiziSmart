<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
abstract class SupplierJournal extends Model {
    use BelongsToBusiness,HasPublicId;
    public $timestamps=false;
    protected $guarded=['id','business_id','public_id'];
    protected static function booted():void {
        static::updating(function($row){foreach(array_keys($row->getDirty()) as $field)if($field!=='remaining_amount')throw new \LogicException('Supplier journals are immutable.');});
        static::deleting(fn()=>throw new \LogicException('Supplier journals cannot be deleted.'));
    }
}
