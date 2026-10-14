<?php
namespace App\Models;
class SupplierOperation extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='sop_';
    protected function casts():array{return ['result'=>'encrypted:array'];}
}
