<?php
// Include only after fixture.php has booted its guarded dedicated test database.
if(!app()->environment('testing')||config('database.default')!=='mysql'||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Margin browser fixtures require dedicated MySQL testing.');
foreach(['desktop','mobile']as$label){
 $business=\App\Models\Business::create(['name'=>'Margin Browser Supermarket','subdomain'=>'e2e-margin-'.$label,'plan_id'=>\App\Models\Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0712222501','vat_rate'=>0]);
 app(\App\Tenancy\TenantContext::class)->run($business,function()use($label){
  app(\App\Services\StaffRoleService::class)->provision();$branch=\App\Models\Branch::create(['name'=>'Main']);
  foreach(['owner'=>'0712222501','cashier'=>'0712222502','stockkeeper'=>'0712222503']as$role=>$phone){$user=\App\Models\User::create(['name'=>'Margin '.$role,'phone'=>$phone,'pin_hash'=>$role==='owner'?'654321':'2468','must_change_pin'=>false,'branch_id'=>$branch->id,'is_active'=>true]);app(\App\Services\StaffRoleService::class)->assign($user,$role);if($role==='owner'){$user->totp_secret='JBSWY3DPEHPK3PXP';$user->totp_confirmed_at=now();$user->save();}\App\Models\Device::create(['device_uid'=>'e2e-margin-'.$label.'-'.$role,'user_id'=>$user->id,'name'=>'Margin test device','status'=>'approved']);if($role==='stockkeeper')$keeper=$user;}
  // Catalogue cost intentionally differs from the immutable receipt batch cost.
  // The UI must display the server's batch-based warning, never infer from this 99.
  $product=\App\Models\Product::create(['name'=>'Margin milk','quantity_type'=>'counted','unit'=>'carton','selling_price'=>1050,'buying_price'=>99,'reorder_level_atoms'=>0,'is_active'=>true]);app(\App\Services\StockService::class)->receive($keeper,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'MARGIN-ACTUAL-COST','qty'=>'4','cost_price'=>1700,'expiry_date'=>null]);
 });
}
