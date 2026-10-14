<?php
if(!app()->environment('testing')||config('database.default')!=='mysql'||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Remaining batch fixtures require dedicated MySQL testing.');
foreach(['desktop','mobile']as $label){
    $admin=\App\Models\AdminUser::create(['name'=>'Browser platform '.$label,'email'=>'platform-'.$label.'@example.test','password'=>'browser-platform-password']);
    $admin->app_authentication_secret=$label==='desktop'?'JBSWY3DPEHPK3PXP':'KRSXG5DSNFXGOIDB';$admin->save();
    // Browser login must verify this authenticator; fixtures never create an authenticated admin session.
    $business=\App\Models\Business::create(['name'=>'Messaging Browser Supermarket','subdomain'=>'e2e-messaging-'.$label,'plan_id'=>\App\Models\Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0712222501']);
    app(\App\Tenancy\TenantContext::class)->run($business,function()use($label){
        app(\App\Services\StaffRoleService::class)->provision();$branch=\App\Models\Branch::create(['name'=>'Main']);$owner=\App\Models\User::create(['name'=>'Messaging owner','phone'=>'0712222501','pin_hash'=>'654321','must_change_pin'=>false,'branch_id'=>$branch->id]);app(\App\Services\StaffRoleService::class)->assign($owner,'owner');$owner->totp_secret='JBSWY3DPEHPK3PXP';$owner->totp_confirmed_at=now();$owner->save();\App\Models\Device::create(['device_uid'=>'e2e-messaging-'.$label,'name'=>'Message test device','status'=>'approved','user_id'=>$owner->id]);$customer=\App\Models\Customer::create(['name'=>'Consenting customer','phone'=>'0712222502','credit_limit'=>10000]);$customer->outstanding_balance=5000;$customer->save();
    });
}
