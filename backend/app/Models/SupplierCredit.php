<?php
namespace App\Models;
class SupplierCredit extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='scredit_';
    protected function casts():array{return ['reference'=>'encrypted','amount'=>'integer','remaining_amount'=>'integer','created_at'=>'immutable_datetime'];}
}
