<?php
use App\Models\{Business,Plan,Branch,Device,User,Product,ProductPackaging,HeldCart,HeldCartOperation,Sale,StockMovement,TillCashEntry};
use App\Services\{StaffRoleService,HoldService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
    config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'holds','name'=>'Holds','price_monthly'=>0,'limits'=>[],'features'=>[]]);
    foreach(['alpha','beta'] as $label){
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;
        $this->context->run($shop,function()use($label){
            app(StaffRoleService::class)->provision();$branch=Branch::create(['name'=>'Main']);$this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'hold-tests','name'=>'Hold tests','status'=>'approved']);$this->{$label.'Device'}=$device;
            foreach(['Cashier','Other'] as $i=>$suffix){
                $actor=User::create(['name'=>$suffix,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]);
                app(StaffRoleService::class)->assign($actor,'cashier');
                $token=$actor->createToken('hold-test',['*'],now()->addDays(30));$token->accessToken->device_id=$device->id;$token->accessToken->save();$actor->withAccessToken($token->accessToken);
                $this->{$label.$suffix}=$actor;$this->{$label.$suffix.'Token'}=$token->plainTextToken;
            }
            $this->{$label.'Product'}=Product::create(['name'=>'Rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>10000,'buying_price'=>1,'reorder_level_atoms'=>0,'is_active'=>true]);
        });
    }
    $this->withToken($this->alphaCashierToken);
    $this->draft=['branch_id'=>$this->alphaBranch->publicId(),'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'0.750','discount'=>100]],'discount'=>50,'discount_reason'=>'Approved at checkout'];
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function heldUrl(string $suffix=''):string{return 'http://alpha.bizismart.test/api/v1/held-carts'.$suffix;}
it('holds an undiscounted cart with an empty optional discount reason',function(){
    $draft=[...$this->draft,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'0.750','discount'=>0]],'discount'=>0,'discount_reason'=>''];
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$draft)->assertCreated()
        ->assertJsonPath('data.draft.discount',0)->assertJsonPath('data.draft.discount_reason','');
});
it('stores an encrypted exact draft once without stock cash sale or approval changes',function(){
    $key=(string)Str::uuid();$this->withHeader('Idempotency-Key',$key);
    $cart=$this->postJson(heldUrl(),$this->draft)->assertCreated()->assertJsonPath('data.draft.items.0.qty','0.75')->assertJsonPath('data.draft.discount',50)->assertJsonMissingPath('data.actor_id')->json('data');
    $this->postJson(heldUrl(),$this->draft)->assertOk()->assertJsonPath('data.id',$cart['id'])->assertJsonPath('data.replayed',true);
    $this->postJson(heldUrl(),[...$this->draft,'discount'=>51])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
    $this->context->run($this->alpha,function(){expect(HeldCart::count())->toBe(1);expect(HeldCartOperation::count())->toBe(1);expect(Sale::count())->toBe(0);expect(StockMovement::count())->toBe(0);expect(TillCashEntry::count())->toBe(0);expect(HeldCart::first()->getRawOriginal('draft'))->not->toContain('product_id');});
});
it('allows one same-branch staff handoff and returns the same draft on uncertain retry',function(){
    $cart=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$this->draft)->assertCreated()->json('data');
    $key=(string)Str::uuid();$this->withToken($this->alphaOtherToken)->withHeader('Idempotency-Key',$key);
    $this->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertOk()->assertJsonPath('data.status','resumed')->assertJsonPath('data.replayed',false);
    $this->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertOk()->assertJsonPath('data.replayed',true);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertConflict()->assertJsonPath('error.code','HOLD_UNAVAILABLE');
    $this->getJson(heldUrl('?branch_id='.$this->alphaBranch->publicId()))->assertOk()->assertJsonCount(0,'data');
});
it('blocks other tenants and branches and refreshes device authority on all operations',function(){
    $cart=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$this->draft)->assertCreated()->json('data');
    $this->withToken($this->betaCashierToken)->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('http://beta.bizismart.test/api/v1/held-carts/'.$cart['id'].'/resume')->assertNotFound();
    $this->withToken($this->alphaOtherToken);
    $this->context->run($this->alpha,function(){ $branch=Branch::create(['name'=>'Other']);$this->alphaOther->branch_id=$branch->id;$this->alphaOther->save(); });
    $this->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertForbidden();
    $this->withToken($this->alphaCashierToken);
    $this->context->run($this->alpha,fn()=> $this->alphaDevice->update(['status'=>'blocked']));
    $this->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertUnauthorized();
});
it('rejects expired holds and unavailable archived products without consuming the cart',function(){
    $cart=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$this->draft)->assertCreated()->json('data');
    $this->context->run($this->alpha,fn()=> $this->alphaProduct->update(['is_active'=>false]));
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertConflict()->assertJsonPath('error.code','PRODUCT_INACTIVE');
    $this->context->run($this->alpha,fn()=>expect(HeldCart::first()->status)->toBe('held'));
    $this->travel(24)->hours();
    $this->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertConflict()->assertJsonPath('error.code','HOLD_EXPIRED');
});
it('deletes held drafts once without a financial supervisor grant and rejects invalid quantities',function(){
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),[...$this->draft,'items'=>[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'0.0001']]])->assertUnprocessable();
    $cart=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$this->draft)->assertCreated()->json('data');
    $key=(string)Str::uuid();$this->withHeader('Idempotency-Key',$key)->deleteJson(heldUrl('/'.$cart['id']))->assertOk()->assertJsonPath('data.status','deleted');
    $this->deleteJson(heldUrl('/'.$cart['id']))->assertOk()->assertJsonPath('data.replayed',true);
    $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl('/'.$cart['id'].'/resume'))->assertConflict();
});
it('refreshes active business state before exposing or changing held drafts',function(){
    $cart=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(heldUrl(),$this->draft)->assertCreated()->json('data');
    $this->context->run($this->alpha,function()use($cart){
        // Keep the request's captured tenant active while another write disables its stored row.
        Business::whereKey($this->alpha->id)->update(['is_active'=>false]);
        expect(fn()=>app(HoldService::class)->list($this->alphaCashier,$this->alphaBranch->publicId()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        expect(fn()=>app(HoldService::class)->claim($this->alphaCashier,$cart['id'],'resume',(string)Str::uuid()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        expect(HeldCart::first()->status)->toBe('held');expect(HeldCartOperation::count())->toBe(1);
    });
});
