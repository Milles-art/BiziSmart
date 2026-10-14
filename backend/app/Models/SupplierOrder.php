<?php
namespace App\Models;
class SupplierOrder extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='po_';
    public $timestamps=true;
    protected function casts():array{return ['lines'=>'encrypted:array','total'=>'integer','revision'=>'integer','issued_at'=>'datetime'];}
    protected static function booted():void {
        static::updating(function($row){if($row->getOriginal('status')!=='draft')throw new \LogicException('Issued orders are immutable.');});
        static::deleting(fn()=>throw new \LogicException('Order history must be retained.'));
    }
}
