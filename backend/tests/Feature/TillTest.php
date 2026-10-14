<?php
use App\Models\{Activity,Branch,Business,Device,Plan,SupervisorApproval,User,TillSession,TillCashEntry,TillOperation};
use App\Services\{StaffRoleService,SupervisorApprovalService,TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $this->service = app(SupervisorApprovalService::class);
    $plan = Plan::create(['code' => 'approval', 'name' => 'Approval', 'price_monthly' => 0, 'limits' => ['tills' => 2], 'features' => []]);
    foreach (['alpha', 'beta'] as $label) {
        $business = Business::create(['name' => $label, 'subdomain' => $label, 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
        $this->{$label} = $business;
        $this->context->run($business, function () use ($label) {
            app(StaffRoleService::class)->provision();
            $branch = Branch::create(['name' => 'Main']);
            $this->{$label.'Branch'} = $branch;
            $device = Device::create(['device_uid' => 'approval-device-01', 'name' => 'Checkout device', 'status' => 'approved']);
            foreach (['owner' => '0700000001', 'manager' => '0700000002', 'cashier' => '0700000003', 'stockkeeper' => '0700000004'] as $role => $phone) {
                $user = User::create(['branch_id' => $branch->id, 'name' => 'Private '.$role, 'phone' => $phone, 'pin_hash' => '1234', 'must_change_pin' => false]);
                app(StaffRoleService::class)->assign($user, $role);
                if ($role === 'owner') {
                    $user->totp_secret = 'JBSWY3DPEHPK3PXP'; $user->totp_confirmed_at = now(); $user->save();
                }
                $token = $user->createToken('approval-test', ['*'], now()->addDays(30));
                $token->accessToken->device_id = $device->id; $token->accessToken->save();
                $user->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)} = $user;
                $this->{$label.ucfirst($role).'Token'} = $token->plainTextToken;
            }
            $this->{$label.'Device'} = $device;
        });
    }
    $this->snapshot = ['branch_id' => $this->alphaBranch->publicId(), 'sale_id' => 'sale_synthetic', 'amount' => 1200, 'reason' => 'Customer return', 'items' => [['batch_id' => 'bat_synthetic', 'qty' => '1']]];
    $this->input = ['phone' => '0700000002', 'pin' => '1234', 'permission' => 'refund',
        'operation_id' => 'a05c7c91-5262-4b3c-9101-1c0c8dd4dd6f', 'payload_hash' => SupervisorApprovalService::snapshotHash($this->snapshot)];
    $this->withToken($this->alphaCashierToken);
});


afterEach(function(){ $this->context->clear(); $this->travelBack(); TillOperation::flushEventListeners(); TillSession::flushEventListeners(); \Illuminate\Database\Eloquent\Model::clearBootedModels(); });
function tillUrl(string $path=''):string{return 'http://alpha.bizismart.test/api/v1/till-sessions'.$path;}
function tillOpen($t,int $float=20000):array{return $t->postJson(tillUrl('/open'),['operation_id'=>(string)Str::uuid(),'branch_id'=>$t->alphaBranch->publicId(),'opening_float'=>$float])->assertCreated()->json('data.till');}
function tillReview($t,array $till,int $counted):array{return $t->postJson(tillUrl('/current/close/preview'),['operation_id'=>(string)Str::uuid(),'till_id'=>$till['id'],'counted_cash'=>$counted])->assertOk()->json('data');}
function tillBody(array $r):array{return array_diff_key($r['snapshot'],['branch_id'=>true]);}
function tillGrant($t,array $r):string{return $t->postJson('http://alpha.bizismart.test/api/v1/auth/supervisor-check',['phone'=>'0700000002','pin'=>'1234','permission'=>'approve_variance','operation_id'=>$r['snapshot']['operation_id'],'payload_hash'=>$r['payload_hash']])->assertOk()->json('data.approval_token');}

it('opens scoped tills with duplicate safe references',function(){
 $input=['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>20000];
 $first=$this->postJson(tillUrl('/open'),$input)->assertCreated()->assertHeader('Cache-Control','no-store, private')->json('data.till');
 expect($first['id'])->toStartWith('till_');expect($first['cashier_id'])->toBe($this->alphaCashier->publicId());
 $this->postJson(tillUrl('/open'),$input)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson(tillUrl('/open'),[...$input,'opening_float'=>30000])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
 $this->postJson(tillUrl('/open'),[...$input,'operation_id'=>(string)Str::uuid()])->assertConflict()->assertJsonPath('error.code','TILL_ALREADY_OPEN');
 $this->getJson(tillUrl('/current'))->assertJsonPath('data.till.id',$first['id']);
});
it('limits open tills across branches and releases a closed slot',function(){
 $this->context->run($this->alpha,fn()=>Plan::firstOrFail()->update(['limits'=>['tills'=>1]]));$first=tillOpen($this);
 $other=$this->context->run($this->alpha,fn()=>Branch::create(['name'=>'Other']));$this->context->run($this->alpha,function()use($other){$this->alphaManager->branch_id=$other->id;$this->alphaManager->save();});
 $input=['operation_id'=>(string)Str::uuid(),'branch_id'=>$other->publicId(),'opening_float'=>0];
 $this->withToken($this->alphaManagerToken)->postJson(tillUrl('/open'),$input)->assertForbidden()->assertJsonPath('error.code','PLAN_LIMIT_REACHED');
 $this->withToken($this->alphaCashierToken);$review=tillReview($this,$first,20000);$this->postJson(tillUrl('/current/close'),tillBody($review))->assertCreated();
 $this->withToken($this->alphaManagerToken)->postJson(tillUrl('/open'),$input)->assertCreated();
});
it('reconciles authoritative cash and protects closed sessions',function(){
 $till=tillOpen($this);$this->context->run($this->alpha,function(){DB::transaction(function(){ $t=TillSession::firstOrFail();$s=app(TillService::class);$s->recordCash($this->alphaCashier,$t,'cash_sale',150000,(string)Str::uuid());$s->recordCash($this->alphaCashier,$t,'cash_refund',5000,(string)Str::uuid());});});
 $r=tillReview($this,$till,164000);expect($r['expected_cash'])->toBe(165000);expect($r['variance'])->toBe(-1000);expect($r['approval_required'])->toBeFalse();
 $body=tillBody($r);$this->postJson(tillUrl('/current/close'),$body)->assertCreated()->assertJsonPath('data.till.status','closed');$this->postJson(tillUrl('/current/close'),$body)->assertOk()->assertJsonPath('data.replayed',true);
 $this->getJson(tillUrl('/current'))->assertJsonPath('data.till',null);
 $this->postJson(tillUrl('/current/paid-out'),['operation_id'=>(string)Str::uuid(),'till_id'=>$till['id'],'amount'=>1,'reason'=>'Transport'])->assertConflict()->assertJsonPath('error.code','TILL_CLOSED');
 $this->context->run($this->alpha,function(){expect(fn()=>TillSession::firstOrFail()->update(['opening_float'=>1]))->toThrow(LogicException::class);expect(fn()=>DB::transaction(fn()=>app(TillService::class)->recordCash($this->alphaCashier,TillSession::firstOrFail(),'cash_sale',1,(string)Str::uuid())))->toThrow(\App\Exceptions\ApiException::class);});
});
it('requires bound variance approval and replays safely after opening another till',function(){
 $t=tillOpen($this);$r=tillReview($this,$t,0);$body=tillBody($r);$this->postJson(tillUrl('/current/close'),$body)->assertForbidden()->assertJsonPath('error.code','SUPERVISOR_REQUIRED');
 $grant=tillGrant($this,$r);$this->postJson(tillUrl('/current/close'),[...$body,'approval_token'=>$grant])->assertCreated()->assertJsonPath('data.till.approved_by',$this->alphaManager->publicId())->assertJsonPath('data.till.owner_review_required',true);
 $new=tillOpen($this,100);$this->postJson(tillUrl('/current/close'),[...$body,'approval_token'=>$grant])->assertOk()->assertJsonPath('data.till.id',$t['id']);$this->getJson(tillUrl('/current'))->assertJsonPath('data.till.id',$new['id']);
});
it('encrypts paid out reasons deduplicates entries and rejects stale cash reviews',function(){
 $t=tillOpen($this);$r=tillReview($this,$t,0);$grant=tillGrant($this,$r);$paid=['operation_id'=>(string)Str::uuid(),'till_id'=>$t['id'],'amount'=>2000,'reason'=>'Private transport reason'];
 $this->postJson(tillUrl('/current/paid-out'),$paid)->assertCreated()->assertJsonPath('data.till.expected_cash',18000);$this->postJson(tillUrl('/current/paid-out'),$paid)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson(tillUrl('/current/close'),[...tillBody($r),'approval_token'=>$grant])->assertConflict()->assertJsonPath('error.code','TILL_CHANGED');
 $this->context->run($this->alpha,function(){expect(TillCashEntry::count())->toBe(1);expect(TillCashEntry::firstOrFail()->getRawOriginal('reason'))->not->toContain('Private transport reason');expect(SupervisorApproval::firstOrFail()->consumed_at)->toBeNull();expect(Activity::where('event','till_paid_out')->firstOrFail()->properties->toJson())->not->toContain('Private transport reason');});
});
it('enforces till role branch and tenant boundaries and validates integer cash',function(){
 $t=tillOpen($this);$this->withToken($this->alphaStockkeeperToken)->getJson(tillUrl())->assertForbidden();$body=['operation_id'=>(string)Str::uuid(),'till_id'=>$t['id'],'counted_cash'=>1];
 $this->withToken($this->betaCashierToken)->postJson(str_replace('alpha.','beta.',tillUrl('/current/close/preview')),$body)->assertNotFound();
 $this->withToken($this->alphaManagerToken)->postJson(tillUrl('/current/close/preview'),$body)->assertForbidden();$this->getJson(tillUrl())->assertJsonPath('meta.total',1);
 $other=$this->context->run($this->alpha,fn()=>Branch::create(['name'=>'Other']));$this->context->run($this->alpha,function()use($other){$this->alphaManager->branch_id=$other->id;$this->alphaManager->save();});$this->getJson(tillUrl())->assertJsonPath('meta.total',0);
 $this->withToken($this->alphaOwnerToken)->getJson(tillUrl())->assertJsonPath('meta.total',1);$this->withToken($this->alphaCashierToken);
 foreach([1.1,'20000',-1,9007199254740992]as$bad){$this->postJson(tillUrl('/open'),['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>$bad])->assertUnprocessable();}
 $this->postJson(tillUrl('/current/close/preview'),[...$body,'business_id'=>$this->alpha->id])->assertUnprocessable();$this->context->run($this->alpha,fn()=>$this->alphaDevice->update(['status'=>'blocked']));$this->getJson(tillUrl('/current'))->assertUnauthorized();
});
it('rolls back a close and its consumed grant on journal failure',function(){
 $t=tillOpen($this);$r=tillReview($this,$t,0);$grant=tillGrant($this,$r);\Illuminate\Support\Facades\Event::listen('eloquent.creating: App\\Models\\TillOperation',fn()=>throw new RuntimeException('Injected operation failure'));
 $this->postJson(tillUrl('/current/close'),[...tillBody($r),'approval_token'=>$grant])->assertStatus(500);$this->context->run($this->alpha,function(){expect(TillSession::firstOrFail()->status)->toBe('open');expect(SupervisorApproval::firstOrFail()->consumed_at)->toBeNull();expect(Activity::where('event','till_closed')->count())->toBe(0);});
});

it('rolls back overflowing cash entries and keeps paid outs separate from drops',function(){
 $till=tillOpen($this,1);$this->context->run($this->alpha,function(){expect(fn()=>DB::transaction(fn()=>app(TillService::class)->recordCash($this->alphaCashier,TillSession::firstOrFail(),'cash_sale',1.5,(string)Str::uuid())))->toThrow(InvalidArgumentException::class);expect(fn()=>DB::transaction(fn()=>app(TillService::class)->recordCash($this->alphaCashier,TillSession::firstOrFail(),'cash_sale',\App\Support\Money::MAX_AMOUNT,(string)Str::uuid())))->toThrow(\App\Exceptions\ApiException::class);expect(TillCashEntry::count())->toBe(0);expect(TillSession::firstOrFail()->revision)->toBe(0);});
});
function tillOpenWorker(int $business,int $actor,int $token,array $input):Closure{return static function()use($business,$actor,$token,$input){
 if(!app()->environment('testing')||!str_ends_with(config('database.connections.mysql.database'),'_test'))throw new LogicException('Test database required.');
 app(TenantContext::class)->set(Business::findOrFail($business));$user=User::findOrFail($actor)->withAccessToken(\App\Models\PersonalAccessToken::findOrFail($token));
 try{return app(TillService::class)->open($user,$input);}catch(\App\Exceptions\ApiException $e){return ['error'=>$e->errorCode];}
};}
it('serializes independent MySQL plan capacity and identical opening retries',function(){
 $this->context->run($this->alpha,fn()=>Plan::firstOrFail()->update(['limits'=>['tills'=>1]]));
 $other=$this->context->run($this->alpha,fn()=>Branch::create(['name'=>'Other']));$this->context->run($this->alpha,function()use($other){$this->alphaManager->branch_id=$other->id;$this->alphaManager->save();});
 $first=['operation_id'=>(string)Str::uuid(),'branch_id'=>$this->alphaBranch->publicId(),'opening_float'=>0];$second=['operation_id'=>(string)Str::uuid(),'branch_id'=>$other->publicId(),'opening_float'=>0];
 $a=(int)explode('|',$this->alphaCashierToken)[0];$b=(int)explode('|',$this->alphaManagerToken)[0];DB::commit();\Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated=false;
 $r=\Illuminate\Support\Facades\Concurrency::driver('process')->run([tillOpenWorker($this->alpha->id,$this->alphaCashier->id,$a,$first),tillOpenWorker($this->alpha->id,$this->alphaManager->id,$b,$second)]);
 expect(array_column($r,'error'))->toBe(['PLAN_LIMIT_REACHED']);
 $this->context->run($this->alpha,function()use($a,$b){expect(TillSession::where('status','open')->count())->toBe(1);$t=TillSession::firstOrFail();$user=User::findOrFail($t->cashier_id)->withAccessToken(\App\Models\PersonalAccessToken::findOrFail($t->cashier_id===$this->alphaCashier->id?$a:$b));$review=app(TillService::class)->preview($user,['operation_id'=>(string)Str::uuid(),'till_id'=>$t->publicId(),'counted_cash'=>0]);app(TillService::class)->close($user,tillBody($review));});
 $input=[...$first,'operation_id'=>(string)Str::uuid()];$duplicates=\Illuminate\Support\Facades\Concurrency::driver('process')->run([tillOpenWorker($this->alpha->id,$this->alphaCashier->id,$a,$input),tillOpenWorker($this->alpha->id,$this->alphaCashier->id,$a,$input)]);
 expect($duplicates[0]['till']['id'])->toBe($duplicates[1]['till']['id']);expect(array_column($duplicates,'replayed'))->toContain(true,false);
});
