<?php
namespace App\Models;
class SupplierAllocation extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='salloc_';
    protected function casts():array{return ['amount'=>'integer'];}
}
