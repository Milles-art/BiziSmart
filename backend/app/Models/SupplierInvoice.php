<?php
namespace App\Models;
class SupplierInvoice extends SupplierJournal {
    public const PUBLIC_ID_PREFIX='sinv_';
    protected function casts():array{return ['invoice_reference'=>'encrypted','lines'=>'encrypted:array','original_amount'=>'integer','remaining_amount'=>'integer','invoice_date'=>'immutable_date','created_at'=>'immutable_datetime'];}
}
