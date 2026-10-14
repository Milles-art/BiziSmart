<?php
use App\Models\{Business,Device,Plan,Product,PricingRule,PricingConsumption,User};
use App\Services\{StaffRoleService,ProductService,StockService,PricingService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function(){
 $this->travelTo(now()->setDate(2026,10,13)->setTime(9,0));config(['tenancy.domain'=>'bizismart.test']);$this->context=app(TenantContext::class);
 $plan=Plan::create(['code'=>'pricing','name'=>'Pricing','price_monthly'=>0,'limits'=>['products'=>100],'features'=>[]]);
 foreach(['alpha','beta']as$label){$shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']);$this->{$label}=$shop;$this->context->run($shop,function()use($label){app(StaffRoleService::class)->provision();$device=Device::create(['device_uid'=>'pricing-test','name'=>'Pricing','status'=>'approved']);foreach(['owner','manager','stockkeeper']as$i=>$role){$u=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false]);app(StaffRoleService::class)->assign($u,$role);if($role==='owner'){$u->totp_secret='JBSWY3DPEHPK3PXP';$u->totp_confirmed_at=now();$u->save();}$token=$u->createToken('pricing',['*'],now()->addDay());$token->accessToken->device_id=$device->id;$token->accessToken->save();$this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;$this->{$label.ucfirst($role)}=$u->withAccessToken($token->accessToken);}});}
 $this->withToken($this->alphaOwnerToken);
 $this->ruleInput=['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'Offer','kind'=>'percentage','priority'=>100,'reason'=>'Owner promotion','starts_at'=>'2026-10-13T11:00','ends_at'=>'2026-10-13T13:00','basis_points'=>5000];
});
afterEach(function(){$this->context->clear();$this->travelBack();});
function pricingUrl(string $suffix='',string $shop='alpha'):string{return 'http://'.$shop.'.bizismart.test/api/v1/pricing/rules'.$suffix;}
function pricingProduct($test,string $name='Item',int $price=1001,string $type='counted'):Product{return $test->context->run($test->alpha,fn()=>app(ProductService::class)->save($test->alphaOwner,['name'=>$name,'barcode'=>$name,'quantity_type'=>$type,'unit'=>$type==='weight'?'kg':'item','buying_price'=>100,'selling_price'=>$price,'reorder_level'=>'0','tracks_expiry'=>false]));}
function pricingReceive($test,Product $p,string $qty,string $number='B1'){return $test->context->run($test->alpha,fn()=>app(StockService::class)->receive($test->alphaOwner,$p,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'qty'=>$qty,'batch_number'=>$number,'cost_price'=>100,'expiry_date'=>null])['batch']);}
function pricingLine(Product $p,string $qty):array{$weight=$p->quantity_type==='weight';return ['product_id'=>$p->publicId(),'name'=>$p->name,'quantity_type'=>$p->quantity_type,'unit'=>$p->unit,'qty'=>$qty,'base_qty'=>$qty,'packaging_id'=>null,'packaging_name'=>null,'packaging_version'=>null,'conversion_atoms'=>$weight?'1000':'1','unit_price'=>$p->selling_price,'discount'=>0,'line_total'=>\App\Support\Money::lineTotal(\App\Support\Quantity::parse($qty,$weight),$p->selling_price,$weight)];}
function pricingQuote($test,array $lines):array{return $test->context->run($test->alpha,fn()=>DB::transaction(function()use($test,$lines){Business::whereKey($test->alpha->id)->lockForUpdate()->firstOrFail();return app(PricingService::class)->apply($test->alphaOwner,$lines);}));}
function pricingCreate($test,array $input):string{$review=$test->postJson(pricingUrl('/preview'),$input)->assertOk()->json('data.review_hash');return $test->postJson(pricingUrl(),[...$input,'review_hash'=>$review])->assertCreated()->json('data.rule.id');}

it('allows only owner configuration and binds immutable UUID replay to actor device and tenant',function(){
 $p=pricingProduct($this);$input=[...$this->ruleInput,'targets'=>[['product_id'=>$p->publicId()]]];$review=$this->postJson(pricingUrl('/preview'),$input)->assertOk()->json('data.review_hash');$body=[...$input,'review_hash'=>$review];$id=$this->postJson(pricingUrl(),$body)->assertCreated()->json('data.rule.id');$this->postJson(pricingUrl(),$body)->assertOk()->assertJsonPath('data.replayed',true);$this->postJson(pricingUrl(),[...$body,'name'=>'Other'])->assertStatus(409)->assertJsonPath('error.code','OPERATION_ID_REUSED');
 foreach(['manager','stockkeeper']as$role)$this->withToken($this->{'alpha'.ucfirst($role).'Token'})->postJson(pricingUrl('/preview'),$input)->assertForbidden();
 $this->withToken($this->betaOwnerToken)->postJson(pricingUrl('/preview','beta'),[...$input,'operation_id'=>(string)\Illuminate\Support\Str::uuid()])->assertNotFound();
 $this->context->run($this->alpha,function()use($id){$r=(new PricingRule)->resolveRouteBinding($id);expect(fn()=>$r->update(['price'=>1]))->toThrow(LogicException::class);});
});
it('chooses one best automatic offer with stable priority ties and exact whole-line percentage rounding',function(){
 $p=pricingProduct($this);pricingReceive($this,$p,'10');$target=[['product_id'=>$p->publicId()]];
 $first=pricingCreate($this,[...$this->ruleInput,'priority'=>20,'targets'=>$target]);$second=pricingCreate($this,[...$this->ruleInput,'operation_id'=>(string)\Illuminate\Support\Str::uuid(),'priority'=>10,'targets'=>$target]);
 $line=pricingQuote($this,[pricingLine($p,'3')])[0];expect($line['unit_price'])->toBe(1001)->and($line['automatic_discount'])->toBe(1501)->and($line['automatic_offers'][0]['id'])->toBe($second)->and(array_sum(array_column($line['pricing_allocations'],'net_total')))->toBe(1502);
 expect($line['automatic_offers'][0]['id'])->not->toBe($first);
});
it('gives the cheapest eligible whole units free across a buy-get basket without touching unrelated products',function(){
 $a=pricingProduct($this,'A',1000);$b=pricingProduct($this,'B',600);$c=pricingProduct($this,'C',100);foreach([$a,$b,$c]as$p)pricingReceive($this,$p,'10');$id=pricingCreate($this,[...array_diff_key($this->ruleInput,['basis_points'=>true]),'kind'=>'buy_get','buy_qty'=>2,'free_qty'=>1,'targets'=>[['product_id'=>$a->publicId()],['product_id'=>$b->publicId()]]]);
 $lines=pricingQuote($this,[pricingLine($a,'2'),pricingLine($b,'1'),pricingLine($c,'1')]);expect($lines[0]['automatic_discount'])->toBe(0)->and($lines[1]['automatic_discount'])->toBe(600)->and($lines[1]['automatic_offers'][0]['id'])->toBe($id)->and($lines[2]['automatic_discount'])->toBe(0);
 $weight=pricingProduct($this,'Weight',1000,'weight');$this->postJson(pricingUrl('/preview'),[...array_diff_key($this->ruleInput,['basis_points'=>true]),'kind'=>'buy_get','buy_qty'=>2,'free_qty'=>1,'targets'=>[['product_id'=>$weight->publicId()]]])->assertUnprocessable();
});
it('binds a markdown to eligible FEFO batch quantities and refuses expired held or oversized targets',function(){
 $p=pricingProduct($this);$first=pricingReceive($this,$p,'2','FIRST');pricingReceive($this,$p,'8','SECOND');$id=pricingCreate($this,[...$this->ruleInput,'kind'=>'markdown','max_qty'=>'1','targets'=>[['product_id'=>$p->publicId(),'batch_id'=>$first->publicId()]]]);$line=pricingQuote($this,[pricingLine($p,'3')])[0];expect($line['automatic_discount'])->toBe(500)->and($line['pricing_allocations'][0]['offer_id'])->toBe($id)->and($line['pricing_allocations'][0]['qty_atoms'])->toBe('1')->and($line['pricing_allocations'][1]['offer_id'])->toBeNull()->and($line['pricing_allocations'][2]['batch_id'])->not->toBe($first->publicId());
 $this->postJson(pricingUrl('/preview'),[...$this->ruleInput,'kind'=>'markdown','max_qty'=>'3','targets'=>[['product_id'=>$p->publicId(),'batch_id'=>$first->publicId()]]])->assertUnprocessable();
 $this->context->run($this->alpha,function()use($first){$first->is_quarantined=true;$first->save();});$this->postJson(pricingUrl('/preview'),[...$this->ruleInput,'kind'=>'markdown','max_qty'=>'1','targets'=>[['product_id'=>$p->publicId(),'batch_id'=>$first->publicId()]]])->assertNotFound();
});
it('activates schedules by Tanzania window and cancels without rewriting saved prices or overlapping schedules',function(){
 $p=pricingProduct($this);pricingReceive($this,$p,'10');$input=[...array_diff_key($this->ruleInput,['basis_points'=>true]),'kind'=>'schedule','price'=>1500,'targets'=>[['product_id'=>$p->publicId()]]];$id=pricingCreate($this,$input);$line=pricingQuote($this,[pricingLine($p,'2')])[0];expect($line['unit_price'])->toBe(1500)->and($line['line_total'])->toBe(3000)->and($line['price_schedule_id'])->toBe($id);
 $this->postJson(pricingUrl('/preview'),[...$input,'operation_id'=>(string)\Illuminate\Support\Str::uuid()])->assertStatus(409)->assertJsonPath('error.code','PRICING_OVERLAP');
 $body=['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'reason'=>'Replace schedule'];$review=$this->postJson(pricingUrl('/'.$id.'/cancel/preview'),$body)->assertOk()->json('data.review_hash');$this->postJson(pricingUrl('/'.$id.'/cancel'),[...$body,'review_hash'=>$review])->assertOk();$this->postJson(pricingUrl('/'.$id.'/cancel'),[...$body,'review_hash'=>$review])->assertOk()->assertJsonPath('data.replayed',true);expect(pricingQuote($this,[pricingLine($p,'2')])[0]['unit_price'])->toBe(1001);
});
it('records exact schedule activation and end history once across repeated reconciliation',function(){
 $p=pricingProduct($this);pricingReceive($this,$p,'10');$input=[...array_diff_key($this->ruleInput,['basis_points'=>true]),'kind'=>'schedule','starts_at'=>'2026-10-13T12:30','ends_at'=>'2026-10-13T13:00','price'=>1500,'targets'=>[['product_id'=>$p->publicId()]]];pricingCreate($this,$input);
 expect(pricingQuote($this,[pricingLine($p,'1')])[0]['unit_price'])->toBe(1001);
 $this->travel(30)->minutes();expect(pricingQuote($this,[pricingLine($p,'1')])[0]['unit_price'])->toBe(1500);
 $this->context->run($this->alpha,function(){expect(app(PricingService::class)->recordEffectiveSchedules())->toBe(0);expect(\App\Models\ProductPriceHistory::where('source','schedule')->count())->toBe(1);});
 $this->travel(30)->minutes();expect(pricingQuote($this,[pricingLine($p,'1')])[0]['unit_price'])->toBe(1001);
 $this->context->run($this->alpha,function(){expect(app(PricingService::class)->recordEffectiveSchedules())->toBe(0);expect(\App\Models\ProductPriceHistory::where('source','schedule')->count())->toBe(2);expect(\App\Models\ProductPriceHistory::where('source','schedule')->orderByDesc('id')->first()->new_price)->toBe(1001);});
});
