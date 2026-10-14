<?php
// Include from fixture.php after its guarded testing bootstrap. Never execute on an operational database.
if(!app()->environment('testing')||config('database.default')!=='mysql'||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new \LogicException('Banking browser fixtures require a dedicated testing schema.');
foreach(['desktop','mobile'] as $label){
 $shop=\App\Models\Business::create(['name'=>'Banking Browser Supermarket','subdomain'=>'e2e-banking-'.$label,'plan_id'=>\App\Models\Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0712222301']);
 app(\App\Tenancy\TenantContext::class)->run($shop,function()use($label){
  app(\App\Services\StaffRoleService::class)->provision();$branch=\App\Models\Branch::create(['name'=>'Main']);
  $owner=\App\Models\User::create(['name'=>'Banking owner','phone'=>'0712222301','pin_hash'=>'654321','must_change_pin'=>false,'branch_id'=>$branch->id,'is_active'=>true]);app(\App\Services\StaffRoleService::class)->assign($owner,'owner');$owner->totp_secret='JBSWY3DPEHPK3PXP';$owner->totp_confirmed_at=now();$owner->save();
  \App\Models\Device::create(['device_uid'=>'e2e-banking-'.$label,'user_id'=>$owner->id,'name'=>'Banking test device','status'=>'approved']);
  $supplier=\App\Models\Supplier::create(['name'=>'Banking supplier','is_active'=>true]);
  $product=\App\Models\Product::create(['name'=>'Banking rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>4000,'buying_price'=>3000,'reorder_level_atoms'=>0,'is_active'=>true]);
  \App\Models\SupplierInvoice::create(['supplier_id'=>$supplier->id,'actor_id'=>$owner->id,'invoice_reference'=>'INV-BANKING','invoice_hash'=>hash('sha256','INV-BANKING'),'original_amount'=>12000,'remaining_amount'=>9000,'invoice_date'=>'2026-10-14','lines'=>[['product_id'=>$product->publicId(),'name'=>'Banking rice','unit'=>'kg','quantity_type'=>'weight','cost_price'=>3000,'base_qty'=>'4']]]);
  \App\Models\SupplierPayment::create(['supplier_id'=>$supplier->id,'actor_id'=>$owner->id,'source'=>'bank','amount'=>3000,'reference'=>'PAID-BANKING','reference_hash'=>hash('sha256','PAID-BANKING'),'paid_on'=>'2026-10-14']);
 });
}
