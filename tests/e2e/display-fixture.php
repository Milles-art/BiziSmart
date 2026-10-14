<?php
// Include from guarded fixture.php; never seed real shop data.
if(!app()->environment('testing')||config('database.default')!=='mysql'||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Display browser fixtures require dedicated MySQL testing.');
foreach(['desktop','mobile']as$label){
 $business=\App\Models\Business::create(['name'=>'Display Browser Supermarket','subdomain'=>'e2e-display-'.$label,'plan_id'=>\App\Models\Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0712222401','vat_rate'=>0]);
 app(\App\Tenancy\TenantContext::class)->run($business,function()use($label){
  app(\App\Services\StaffRoleService::class)->provision();$branch=\App\Models\Branch::create(['name'=>'Main']);
  $owner=\App\Models\User::create(['name'=>'Display owner','phone'=>'0712222401','pin_hash'=>'654321','must_change_pin'=>false,'branch_id'=>$branch->id,'is_active'=>true]);app(\App\Services\StaffRoleService::class)->assign($owner,'owner');$owner->totp_secret='JBSWY3DPEHPK3PXP';$owner->totp_confirmed_at=now();$owner->save();\App\Models\Device::create(['device_uid'=>'e2e-display-'.$label,'user_id'=>$owner->id,'name'=>'Display test device','status'=>'approved']);
  $keeper=\App\Models\User::create(['name'=>'Display stockkeeper','phone'=>'0712222402','pin_hash'=>'2468','must_change_pin'=>false,'branch_id'=>$branch->id,'is_active'=>true]);app(\App\Services\StaffRoleService::class)->assign($keeper,'stockkeeper');
  $product=\App\Models\Product::create(['name'=>'Display rice','barcode'=>'00123','quantity_type'=>'weight','unit'=>'kg','selling_price'=>4000,'buying_price'=>3000,'reorder_level_atoms'=>0,'is_active'=>true]);app(\App\Services\StockService::class)->receive($keeper,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'DISPLAY-STOCK','qty'=>'5','cost_price'=>3000,'expiry_date'=>null]);
  \App\Models\Customer::create(['name'=>'Private card customer','phone'=>'0712222403','credit_limit'=>0]);
 });
}
