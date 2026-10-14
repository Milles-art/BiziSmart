<?php
namespace App\Models;
class SupplierPayment extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='spay_';
    protected function casts():array{return ['reference'=>'encrypted','amount'=>'integer','paid_on'=>'immutable_date','created_at'=>'immutable_datetime'];}
}
