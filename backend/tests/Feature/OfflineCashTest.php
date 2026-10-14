<?php
use App\Models\{Business,Branch,Device,OfflineLease,OfflineOperation,Plan,Product,ProductBatch,Sale,SaleItem,StockMovement,TillCashEntry,TillSession,User};
use App\Services\{OfflineSaleService,StaffRoleService,StockService,TillService};
use App\Support\OfflineProof;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshPaymentDatabase;
uses(RefreshPaymentDatabase::class);

beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'offline','name'=>'Offline tests','price_monthly'=>0,'limits'=>['tills'=>10],'features'=>[]]);
    foreach(['alpha','beta']as$label){
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
        $this->context->run($shop,function()use($label){
            app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'offline_cashier_device','name'=>'Offline cashier','status'=>'approved']);$this->{$label.'Device'}=$device;
            $ownerDevice=Device::create(['device_uid'=>'offline_owner_device','name'=>'Offline owner','status'=>'approved']);$this->{$label.'OwnerDevice'}=$ownerDevice;
            foreach(['owner','cashier','manager','stockkeeper']as$i=>$role){
                $actor=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);app(StaffRoleService::class)->assign($actor,$role);
                if($role==='owner'){$actor->totp_secret='JBSWY3DPEHPK3PXP';$actor->totp_confirmed_at=now();$actor->save();}
                $token=$actor->createToken('offline',['*'],now()->addDays(5));$token->accessToken->device_id=$role==='owner'?$ownerDevice->id:$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$actor;$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
            }
            $this->{$label.'Till'}=app(TillService::class)->open($this->{$label.'Cashier'},['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
            $rice=Product::create(['name'=>'Rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>10000,'buying_price'=>6000,'reorder_level_atoms'=>0,'tracks_expiry'=>true,'is_active'=>true]);$this->{$label.'Product'}=$rice;
            app(StockService::class)->receive($this->{$label.'Stockkeeper'},$rice,['operation_id'=>(string)Str::uuid(),'batch_number'=>'OFFLINE-RICE','qty'=>'2.000','cost_price'=>6000,'expiry_date'=>now()->addDays(2)->toDateString()]);
        });
    }
    $this->withToken($this->alphaCashierToken);
    $this->lease=$this->postJson(offlineUrl('/leases'))->assertCreated()->json('data');
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function offlineUrl(string $suffix,string $tenant='alpha'):string{return 'http://'.$tenant.'.bizismart.test/api/v1/offline'.$suffix;}
function offlineBody($test,string $qty='0.750'):array{
    $item=collect($test->lease['catalogue'])->firstWhere('product_id',$test->alphaProduct->publicId());
    return ['lease_id'=>$test->lease['lease_id'],'device_uid'=>$test->lease['device_uid'],'operation_id'=>(string)Str::uuid(),'captured_at'=>now()->format('Y-m-d\TH:i:s.v\Z'),'cash_tendered'=>40000,
        'items'=>[['snapshot_id'=>$item['snapshot_id'],'proof'=>$item['proof'],'qty'=>$qty]]];
}
function offlineSync($test,array $body,?string $signature=null,string $tenant='alpha'){
    return $test->withHeaders(['Authorization'=>'','X-Offline-Signature'=>$signature??OfflineProof::sign($body,$test->lease['secret'])])->postJson(offlineUrl('/sync',$tenant),$body);
}
it('issues a precise 24-hour cash-only signed catalogue without costs or internal identities',function(){
    $issued=\Carbon\CarbonImmutable::parse($this->lease['issued_at']);$expires=\Carbon\CarbonImmutable::parse($this->lease['expires_at']);
    expect($expires->getTimestampMs()-$issued->getTimestampMs())->toBe(86400000);expect($this->lease['cashier_id'])->toBe($this->alphaCashier->publicId());expect($this->lease['automatic_offers_enabled'])->toBeFalse();
    expect($this->lease['catalogue'][0]['unit_price'])->toBe(10000);expect($this->lease['catalogue'][0])->not->toHaveKeys(['cost_price','buying_price','product_key','batch_keys','business_id']);
    expect($this->lease['catalogue'][0]['proof'])->toMatch('/^[a-f0-9]{64}$/');
    $this->context->run($this->alpha,function(){expect(OfflineLease::count())->toBe(1);expect(Sale::count())->toBe(0);});
});
it('commits exact gram cash stock and till journals once and rejects changed duplicate details',function(){
    $body=offlineBody($this);$result=offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','committed')->assertJsonPath('data.sale.total',7500)->assertJsonPath('data.sale.change_given',32500)->assertJsonPath('data.sale.lines.0.base_qty','0.75')->json('data');
    offlineSync($this,$body)->assertOk()->assertJsonPath('data.sale.id',$result['sale']['id'])->assertJsonPath('data.replayed',true);
    offlineSync($this,[...$body,'cash_tendered'=>50000])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->context->run($this->alpha,function()use($body){
        expect(Sale::count())->toBe(1);expect(OfflineOperation::count())->toBe(1);expect(StockMovement::where('type','sale')->count())->toBe(1);expect(SaleItem::first()->qty_atoms)->toBe(750);expect(SaleItem::first()->cost_price)->toBe(6000);
        expect(ProductBatch::first()->qty_remaining_atoms)->toBe(1250);expect(TillCashEntry::where('type','cash_sale')->sum('amount'))->toBe('7500');expect(app(TillService::class)->current($this->alphaCashier)['till']['expected_cash'])->toBe(27500);
        expect(Sale::first()->is_offline)->toBeTrue();expect(Sale::first()->occurred_at->getTimestampMs())->toBe(\Carbon\CarbonImmutable::parse($body['captured_at'])->startOfSecond()->getTimestampMs());
    });
});
it('accepts genuine negative stock and stale expired recalled prices while flagging each conflict',function(){
    $body=offlineBody($this,'3.000');
    $this->context->run($this->alpha,function(){ProductBatch::first()->update(['expiry_date'=>now()->subDay()->toDateString(),'is_recalled'=>true]);$this->alphaProduct->update(['selling_price'=>15000]);});
    $result=offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','committed_with_conflict')->assertJsonPath('data.sale.total',30000)->json('data');
    expect($result['conflicts'])->toContain('NEGATIVE_STOCK','EXPIRED_BATCH','RECALLED_BATCH','PRICE_CHANGED');
    $this->withToken($this->alphaOwnerToken)->getJson(offlineUrl('/conflicts'))->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.sales.0.sale_id',$result['sale']['id'])->assertJsonPath('data.sales.0.total',30000)->assertJsonPath('data.sales.0.owner_reconciled',false)->assertHeader('Cache-Control','no-store, private');
    $this->withToken($this->alphaManagerToken)->getJson(offlineUrl('/conflicts'))->assertForbidden();
    $this->withToken($this->betaOwnerToken)->getJson(offlineUrl('/conflicts','beta'))->assertOk()->assertJsonPath('meta.total',0);

    $this->context->run($this->alpha,function(){expect(ProductBatch::first()->qty_remaining_atoms)->toBe(-1000);expect(SaleItem::sum('qty_atoms'))->toBe('3000');expect(TillCashEntry::where('type','cash_sale')->sum('amount'))->toBe('30000');});
});
it('rejects altered body authentication and cross-tenant leases without recording financial data',function(){
    $body=offlineBody($this);$signature=OfflineProof::sign($body,$this->lease['secret']);
    offlineSync($this,[...$body,'cash_tendered'=>40001],$signature)->assertUnauthorized();
    offlineSync($this,$body,null,'beta')->assertUnauthorized();
    foreach([$this->alpha,$this->beta]as$business)$this->context->run($business,function(){expect(Sale::count())->toBe(0);expect(OfflineOperation::count())->toBe(0);expect(StockMovement::where('type','sale')->count())->toBe(0);});
});
it('preserves validly authenticated tampered snapshot records in quarantine without accepting invented prices',function(){
    $body=offlineBody($this);$body['items'][0]['proof']=str_repeat('0',64);
    offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','quarantined')->assertJsonPath('data.conflicts.0','PAYLOAD_INVALID')->assertJsonPath('data.sale',null);
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(ProductBatch::first()->qty_remaining_atoms)->toBe(2000);expect(OfflineOperation::first()->payload['items'][0]['proof'])->toBe(str_repeat('0',64));});
});
it('quarantines revoked devices and out-of-window leases while preserving the immutable queued evidence',function(){
    $body=offlineBody($this);$this->context->run($this->alpha,fn()=>$this->alphaDevice->update(['status'=>'blocked']));
    $result=offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','quarantined')->json('data');expect($result['conflicts'])->toContain('DEVICE_REVOKED');
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(OfflineOperation::count())->toBe(1);});
});
it('records an expired-lease sale only as quarantine and makes exact replay harmless',function(){
    $body=offlineBody($this);$this->travel(25)->hours();
    offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','quarantined');offlineSync($this,$body)->assertOk()->assertJsonPath('data.replayed',true);
    $this->context->run($this->alpha,function(){expect(OfflineOperation::first()->conflicts)->toContain('LEASE_EXPIRED');expect(Sale::count())->toBe(0);expect(ProductBatch::first()->qty_remaining_atoms)->toBe(2000);});
});
it('requires owner approval to reconcile closed-till cash into an own open till preserving original attribution',function(){
    $body=offlineBody($this);
    $close=['operation_id'=>(string)Str::uuid(),'till_id'=>$this->alphaTill['id'],'counted_cash'=>20000];
    $snapshot=$this->withToken($this->alphaCashierToken)->postJson('http://alpha.bizismart.test/api/v1/till-sessions/current/close/preview',$close)->assertOk()->json('data.snapshot');
    $this->postJson('http://alpha.bizismart.test/api/v1/till-sessions/current/close',$snapshot)->assertCreated();
    $old=$this->context->run($this->alpha,fn()=>(new TillSession)->resolveRouteBinding($this->alphaTill['id'])->getAttributes());
    $quarantine=offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','quarantined')->json('data');expect($quarantine['conflicts'])->toContain('TILL_CLOSED');
    $ownerTill=$this->context->run($this->alpha,fn()=>app(TillService::class)->open($this->alphaOwner,['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>0])['till']);
    $input=['operation_id'=>(string)Str::uuid(),'till_id'=>$ownerTill['id'],'reason'=>'Owner counted and received late offline cash'];
    $path='/quarantine/'.$quarantine['id'];
    $this->withToken($this->alphaManagerToken)->postJson(offlineUrl($path.'/preview'),$input)->assertForbidden();
    $review=$this->withToken($this->alphaOwnerToken)->postJson(offlineUrl($path.'/preview'),$input)->assertOk()->assertJsonPath('data.total',7500)->json('data');
    $grant=$this->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0710000000','pin'=>'1234','code'=>app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'),'permission'=>'manage_settings','operation_id'=>$input['operation_id'],'payload_hash'=>$review['review_hash']])->assertOk()->json('data.approval_token');
    $commit=[...$input,'review_hash'=>$review['review_hash'],'approval_token'=>$grant];
    $result=$this->postJson(offlineUrl($path.'/reconcile'),$commit)->assertOk()->assertJsonPath('data.sale.cashier_id',$this->alphaCashier->publicId())->assertJsonPath('data.sale.till_id',$ownerTill['id'])->json('data');
    $this->postJson(offlineUrl($path.'/reconcile'),$commit)->assertOk()->assertJsonPath('data.replayed',true);
    $this->context->run($this->alpha,function()use($old){expect((new TillSession)->resolveRouteBinding($this->alphaTill['id'])->getAttributes())->toBe($old);expect(Sale::count())->toBe(1);expect(TillCashEntry::where('type','cash_sale')->count())->toBe(1);expect(app(TillService::class)->current($this->alphaOwner)['till']['expected_cash'])->toBe(7500);expect(OfflineOperation::first()->resolved_by)->toBe($this->alphaOwner->id);});
});
it('rolls back stock sale and queue commit on journal failure and allows the same signed operation to retry',function(){
    $body=offlineBody($this);$real=app(TillService::class);
    $this->mock(TillService::class,fn($mock)=>$mock->shouldReceive('recordCash')->once()->andThrow(new RuntimeException('Injected offline journal failure')));app()->forgetInstance(OfflineSaleService::class);
    offlineSync($this,$body)->assertStatus(500);
    $this->context->run($this->alpha,function(){expect(Sale::count())->toBe(0);expect(OfflineOperation::count())->toBe(0);expect(StockMovement::where('type','sale')->count())->toBe(0);expect(ProductBatch::first()->qty_remaining_atoms)->toBe(2000);});
    app()->instance(TillService::class,$real);app()->forgetInstance(OfflineSaleService::class);app('router')->getRoutes()->getByName('offline.sync')->flushController();
    offlineSync($this,$body)->assertCreated()->assertJsonPath('data.sale.total',7500);
});

it('reports late-synchronised commission accrual on the original counter sale date',function(){
    $body=offlineBody($this);$captured=\Carbon\CarbonImmutable::parse($body['captured_at']);
    $this->withToken($this->alphaOwnerToken);$config=['operation_id'=>(string)Str::uuid(),'loyalty_tsh_per_point'=>null,'loyalty_point_value'=>null,'commission_basis_points'=>1000,'reason'=>'Report commission event'];
    $review=$this->postJson('http://alpha.bizismart.test/api/v1/incentives/configuration/preview',$config)->assertOk()->json('data.review_hash');
    $this->postJson('http://alpha.bizismart.test/api/v1/incentives/configuration',[...$config,'review_hash'=>$review])->assertCreated();
    $body['captured_at']=now()->addSecond()->format('Y-m-d\TH:i:s.v\Z');$captured=\Carbon\CarbonImmutable::parse($body['captured_at']);
    $this->travelTo($captured->setTimezone('Africa/Dar_es_Salaam')->startOfDay()->addDay()->addMinute()->utc());
    offlineSync($this,$body)->assertCreated()->assertJsonPath('data.status','committed');
    $date=$captured->setTimezone('Africa/Dar_es_Salaam')->toDateString();$next=$captured->setTimezone('Africa/Dar_es_Salaam')->addDay()->toDateString();
    $this->withToken($this->alphaOwnerToken)->getJson('http://alpha.bizismart.test/api/v1/reports/summary?from='.$date.'&to='.$date)->assertOk()->assertJsonPath('data.summary.sales_gross',7500)->assertJsonPath('data.summary.sales_vat',1144)->assertJsonPath('data.summary.net_revenue',6356)->assertJsonPath('data.summary.commission_accrual',636)->assertJsonPath('data.summary.net_profit',1220);
    $this->getJson('http://alpha.bizismart.test/api/v1/reports/summary?from='.$next.'&to='.$next)->assertOk()->assertJsonPath('data.summary.sales_gross',0)->assertJsonPath('data.summary.commission_accrual',0);
});
