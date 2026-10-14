<?php
use App\Models\{AdminUser,Business,Branch,Plan,User,Device,Subscription,Customer,MessageDelivery};
use App\Services\{StaffRoleService,MessagingService};
use App\Filament\Services\PlatformService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $features=array_fill_keys(config('platform.features'),true);$features['tra']=false;
    $this->plan=Plan::create(['code'=>'platform-test','name'=>'Test','price_monthly'=>70000,'limits'=>['tills'=>2,'users'=>5,'products'=>200],'features'=>$features]);
    $this->alpha=Business::create(['name'=>'Alpha','subdomain'=>'alpha','plan_id'=>$this->plan->id,'owner_phone'=>'255700000001']);
    $this->beta=Business::create(['name'=>'Beta','subdomain'=>'beta','plan_id'=>$this->plan->id,'owner_phone'=>'255700000001']);
    $this->context->run($this->alpha,function(){app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$device=Device::create(['name'=>'Test','device_uid'=>'platform-test','status'=>'approved']);foreach(['owner','cashier']as$i=>$role){$u=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($u,$role);if($role==='owner'){$u->totp_secret='JBSWY3DPEHPK3PXP';$u->totp_confirmed_at=now();$u->save();}$t=$u->createToken('platform-test',['*'],now()->addDay());$t->accessToken->device_id=$device->id;$t->accessToken->save();$this->{$role}=$u;$this->{$role.'Token'}=$t->plainTextToken;}$this->customer=Customer::create(['name'=>'Customer','phone'=>'0750000001','credit_limit'=>10000]);});
    $this->context->run($this->alpha,function(){ $token=\App\Models\PersonalAccessToken::where('tokenable_id',$this->owner->id)->firstOrFail();$this->owner->withAccessToken($token); });
    $this->admin=AdminUser::create(['name'=>'Platform','email'=>'platform@example.test','password'=>'long-test-password']);$this->admin->app_authentication_secret='JBSWY3DPEHPK3PXP';$this->admin->save();$this->withToken($this->ownerToken);
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function platformMessageUrl(string $path):string{return 'http://alpha.bizismart.test/api/v1/'.$path;}
function messageConsentBody($test):array{return ['customer_id'=>$test->customer->publicId(),'expected_revision'=>0,'whatsapp'=>true,'sms'=>true,'receipts'=>true,'debt_reminders'=>true,'evidence'=>'Customer explicitly agreed in store'];}
it('onboards once with roles branch managed term and the existing expiring one-use owner setup',function(){
    $input=['name'=>'New shop','subdomain'=>'new-shop','owner_phone'=>'0712345678','plan_code'=>$this->plan->code,'starts_on'=>now('Africa/Dar_es_Salaam')->toDateString(),'ends_on'=>now('Africa/Dar_es_Salaam')->addMonth()->toDateString()];$key=(string)Str::uuid();$service=app(PlatformService::class);$first=$service->execute($this->admin,'onboard',$input,$key);$again=$service->execute($this->admin,'onboard',$input,$key);expect($again['replayed'])->toBeTrue()->and($again['setup_url'])->toBe($first['setup_url']);expect(Business::where('subdomain','new-shop')->count())->toBe(1);$shop=Business::where('subdomain','new-shop')->first();$this->context->run($shop,function(){expect(Branch::count())->toBe(1)->and(Subscription::count())->toBe(1)->and(\App\Models\OwnerInvitation::whereNull('consumed_at')->count())->toBe(1)->and(User::count())->toBe(0);});expect($first['setup_url'])->toContain('#setup=');
    expect(fn()=>$service->execute($this->admin,'onboard',[...$input,'name'=>'Changed'],$key))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('denies platform writes without mandatory admin TOTP and rejects unresolved plan assignments',function(){
    $this->admin->app_authentication_secret=null;$this->admin->save();expect(fn()=>app(PlatformService::class)->execute($this->admin,'plan',[],(string)Str::uuid()))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    $this->get('http://admin.bizismart.co.tz/platform-management')->assertRedirect('http://admin.bizismart.co.tz/login');
});
it('consumes each admin TOTP timestep only once under a database lock',function(){
    $provider=app(\App\Filament\Auth\AdminAppAuthentication::class)->codeWindow(1);$code=app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');expect($provider->verifyCode($code,'JBSWY3DPEHPK3PXP',true))->toBeTrue()->and($provider->verifyCode($code,'JBSWY3DPEHPK3PXP',true))->toBeFalse();
});
it('gates suspended and expired operations while status sign-in and logout stay reachable',function(){
    $this->context->run($this->alpha,fn()=>Subscription::create(['plan_id'=>$this->plan->id,'starts_at'=>now()->subDay(),'ends_at'=>now()->addDay(),'status'=>'suspended']));
    $this->getJson(platformMessageUrl('products'))->assertForbidden()->assertJsonPath('error.code','SUBSCRIPTION_INACTIVE');$this->getJson(platformMessageUrl('subscription-status'))->assertOk()->assertJsonPath('data.active',false);
    $this->context->run($this->alpha,function(){$s=Subscription::first();$s->status='active';$s->ends_at=now()->subMinute();$s->save();});$this->getJson(platformMessageUrl('products'))->assertForbidden()->assertJsonPath('error.code','SUBSCRIPTION_INACTIVE');
});
it('enforces explicit plan overrides in Pennant endpoints and reports them in me',function(){
    $this->alpha->settings=['feature_overrides'=>['reports'=>false,'scales'=>false]];$this->alpha->save();$this->getJson(platformMessageUrl('reports/summary?from=2026-10-14&to=2026-10-14'))->assertForbidden()->assertJsonPath('error.code','FEATURE_DISABLED');$this->getJson(platformMessageUrl('scales/profile'))->assertForbidden()->assertJsonPath('error.code','FEATURE_DISABLED');$this->getJson(platformMessageUrl('me'))->assertOk()->assertJsonPath('data.features.reports',false);
    $this->alpha->settings=['feature_overrides'=>['reports'=>true,'scales'=>true]];$this->alpha->save();$this->getJson(platformMessageUrl('scales/profile'))->assertOk();
});
it('records subscription payments atomically once and rejects stale revisions',function(){
    $input=['business_id'=>$this->alpha->publicId(),'plan_code'=>$this->plan->code,'expected_revision'=>0,'starts_on'=>now('Africa/Dar_es_Salaam')->toDateString(),'ends_on'=>now('Africa/Dar_es_Salaam')->addMonth()->toDateString(),'status'=>'active','payment'=>['amount'=>70000,'method'=>'bank','reference'=>'BANK-TERM-1','paid_on'=>now('Africa/Dar_es_Salaam')->toDateString()]];$key=(string)Str::uuid();$service=app(PlatformService::class);$service->execute($this->admin,'subscription',$input,$key);expect($service->execute($this->admin,'subscription',$input,$key)['replayed'])->toBeTrue();expect(fn()=>$service->execute($this->admin,'subscription',$input,(string)Str::uuid()))->toThrow(\Illuminate\Validation\ValidationException::class);$this->context->run($this->alpha,fn()=>expect(\App\Models\SubscriptionPayment::count())->toBe(1));
});
it('rejects unassigned features TRA activation and plan reductions below current tenant usage atomically',function(){
    $service=app(PlatformService::class);$features=array_fill_keys(config('platform.features'),false);
    $input=['code'=>$this->plan->code,'name'=>'Changed plan','price_monthly'=>'150000','limits'=>['tills'=>2,'users'=>1,'products'=>200],'features'=>$features];
    expect(fn()=>$service->execute($this->admin,'plan',[...$input,'features'=>[]],(string)Str::uuid()))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn()=>$service->execute($this->admin,'plan',[...$input,'features'=>[...$features,'tra'=>true]],(string)Str::uuid()))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn()=>$service->execute($this->admin,'plan',$input,(string)Str::uuid()))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($this->plan->fresh()->price_monthly)->toBe(70000)->and($this->plan->fresh()->limits['users'])->toBe(5);
    $service->execute($this->admin,'plan',[...$input,'limits'=>[...$input['limits'],'users'=>2]],(string)Str::uuid());
    expect($this->plan->fresh()->price_monthly)->toBe(150000)->and($this->plan->fresh()->features['reports'])->toBeFalse();
    $this->getJson(platformMessageUrl('reports/summary?from=2026-10-14&to=2026-10-14'))->assertForbidden()->assertJsonPath('error.code','FEATURE_DISABLED');
});
it('requires consent and stores the same customer consent operation once',function(){
    $body=messageConsentBody($this);$key=(string)Str::uuid();$this->withHeader('Idempotency-Key',$key)->postJson(platformMessageUrl('messaging/consent'),$body)->assertOk()->assertJsonPath('data.consent.revision',1);$this->postJson(platformMessageUrl('messaging/consent'),$body)->assertOk()->assertJsonPath('data.replayed',true);$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(platformMessageUrl('messaging/consent'),$body)->assertConflict()->assertJsonPath('error.code','CONSENT_CHANGED');
    $this->context->run($this->alpha,function()use($body){expect(\App\Models\CustomerMessageConsent::count())->toBe(1);expect(\Illuminate\Support\Facades\DB::table('customer_message_consents')->where('business_id',$this->alpha->id)->value('evidence'))->not->toBe($body['evidence']);});
});
it('blocks unconfigured delivery without network calls then cancels it after consent revocation',function(){
    Http::fake();$this->context->run($this->alpha,function(){ $service=app(MessagingService::class);$service->commit($this->owner,'consent',messageConsentBody($this),(string)Str::uuid());$r=$service->commit($this->owner,'debt_reminder',['customer_id'=>$this->customer->publicId(),'channel'=>'sms'],(string)Str::uuid());$row=MessageDelivery::first();$service->dispatchOne($row->id);expect($row->refresh()->status)->toBe('blocked');$service->commit($this->owner,'consent',[...messageConsentBody($this),'expected_revision'=>1,'sms'=>false],(string)Str::uuid());$service->dispatchOne($row->id);expect($row->refresh()->status)->toBe('cancelled');});Http::assertNothingSent();
});
it('distinguishes provider acceptance from delivery and never repeats an uncertain provider call',function(){
    config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.sms.key'=>'test-key','messaging.sms.secret'=>'test-secret','messaging.sms.sender'=>'TEST']);Http::fake(['apisms.beem.africa/*'=>Http::sequence()->push(['successful'=>true,'code'=>100,'request_id'=>123],200)->push([],503)]);
    $this->context->run($this->alpha,function(){ $service=app(MessagingService::class);$service->commit($this->owner,'consent',messageConsentBody($this),(string)Str::uuid());$service->commit($this->owner,'debt_reminder',['customer_id'=>$this->customer->publicId(),'channel'=>'sms'],(string)Str::uuid());$row=MessageDelivery::first();$service->dispatchOne($row->id);$service->dispatchOne($row->id);expect($row->refresh()->status)->toBe('accepted')->and($row->delivered_at)->toBeNull();});Http::assertSentCount(1);
    $this->context->run($this->alpha,function(){ $row=MessageDelivery::create(['event_key'=>hash('sha256','uncertain'),'channel'=>'sms','purpose'=>'debt_reminders','customer_id'=>$this->customer->id,'recipient'=>$this->customer->phone,'message'=>'Reminder','status'=>'queued']);$service=app(MessagingService::class);$service->dispatchOne($row->id);$service->dispatchOne($row->id);expect($row->refresh()->status)->toBe('uncertain');});Http::assertSentCount(2);
});
it('rejects foreign customers and customer receipt identities rather than accepting recipient input',function(){
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(platformMessageUrl('messaging/consent'),[...messageConsentBody($this),'recipient'=>'255700000001'])->assertUnprocessable();$foreign=$this->context->run($this->beta,fn()=>Customer::create(['name'=>'Foreign','phone'=>'0750000002','credit_limit'=>0]));$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(platformMessageUrl('messaging/consent'),[...messageConsentBody($this),'customer_id'=>$foreign->publicId()])->assertNotFound();$this->withToken($this->cashierToken)->getJson(platformMessageUrl('messaging'))->assertForbidden();
});
it('quarantines a reused provider identifier without retrying or corrupting the first delivery',function(){
    config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.sms.key'=>'test-key','messaging.sms.secret'=>'test-secret','messaging.sms.sender'=>'TEST']);Http::fake(['apisms.beem.africa/*'=>Http::response(['successful'=>true,'code'=>100,'request_id'=>456],200)]);
    $this->context->run($this->alpha,function(){
        $service=app(MessagingService::class);$service->commit($this->owner,'consent',messageConsentBody($this),(string)Str::uuid());
        $first=MessageDelivery::create(['event_key'=>hash('sha256','first-id'),'channel'=>'sms','purpose'=>'debt_reminders','customer_id'=>$this->customer->id,'recipient'=>$this->customer->phone,'message'=>'First','status'=>'queued']);$service->dispatchOne($first->id);
        $second=MessageDelivery::create(['event_key'=>hash('sha256','second-id'),'channel'=>'sms','purpose'=>'debt_reminders','customer_id'=>$this->customer->id,'recipient'=>$this->customer->phone,'message'=>'Second','status'=>'queued']);$service->dispatchOne($second->id);$service->dispatchOne($second->id);
        expect($first->refresh()->status)->toBe('accepted')->and($second->refresh()->status)->toBe('uncertain')->and($second->failure_code)->toBe('PROVIDER_ID_CONFLICT')->and($second->provider_id)->toBeNull();
    });Http::assertSentCount(2);
});
it('accepts only signed bound WhatsApp delivery evidence and preserves delivered status on later failures',function(){
    config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.whatsapp.app_secret'=>'test-app-secret','messaging.whatsapp.phone_number_id'=>'123']);$row=$this->context->run($this->alpha,fn()=>MessageDelivery::create(['event_key'=>hash('sha256','webhook'),'channel'=>'whatsapp','purpose'=>'receipts','customer_id'=>$this->customer->id,'recipient'=>$this->customer->phone,'message'=>'Receipt','status'=>'accepted','provider_id'=>'wamid.test']));
    $payload=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'123'],'statuses'=>[['id'=>'wamid.test','status'=>'delivered']]]]]]]];$body=json_encode($payload);$url='http://api.bizismart.test/api/v1/messaging/whatsapp/webhook';$this->postJson($url,$payload)->assertUnauthorized();$this->call('POST',$url,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>'sha256='.hash_hmac('sha256',$body,'test-app-secret')],$body)->assertOk();$this->context->run($this->alpha,fn()=>expect($row->refresh()->status)->toBe('delivered'));
});
it('retains early signed delivery evidence until the dispatch response supplies its provider ID',function(){
    config(['messaging.business_public_id'=>$this->alpha->publicId(),'messaging.whatsapp.app_secret'=>'test-app-secret','messaging.whatsapp.phone_number_id'=>'123']);
    $payload=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'123'],'statuses'=>[['id'=>'wamid.early','status'=>'delivered']]]]]]]];$body=json_encode($payload);$url='http://api.bizismart.test/api/v1/messaging/whatsapp/webhook';
    $this->call('POST',$url,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_HUB_SIGNATURE_256'=>'sha256='.hash_hmac('sha256',$body,'test-app-secret')],$body)->assertOk();
    $this->context->run($this->alpha,function(){expect(\App\Models\MessageProviderEvent::count())->toBe(1);$row=MessageDelivery::create(['event_key'=>hash('sha256','early'),'channel'=>'whatsapp','purpose'=>'receipts','customer_id'=>$this->customer->id,'recipient'=>$this->customer->phone,'message'=>'Receipt','status'=>'accepted','provider_id'=>'wamid.early']);\Illuminate\Support\Facades\DB::transaction(function()use($row){$row->refreshForUpdate();app(\App\Services\MessageEvidenceService::class)->apply($row);});expect($row->refresh()->status)->toBe('delivered');});
});
it('refuses fixture cleanup against an unapproved or different live database without deleting records',function(){
    $name=config('database.connections.mysql.database');
    config(['database.connections.mysql.database'=>'bizismart']);expect(fn()=>\Tests\Support\GuardedTestDatabase::clear())->toThrow(\LogicException::class);
    config(['database.connections.mysql.database'=>'different_test']);expect(fn()=>\Tests\Support\GuardedTestDatabase::clear())->toThrow(\LogicException::class);
    config(['database.connections.mysql.database'=>$name]);$this->context->run($this->alpha,fn()=>expect(Customer::count())->toBe(1));
});
