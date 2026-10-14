<?php
namespace App\Models;
class SupplierReturn extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='sret_';
    protected function casts():array{return ['reason'=>'encrypted','lines'=>'encrypted:array','expected_credit'=>'integer','created_at'=>'immutable_datetime'];}
}
