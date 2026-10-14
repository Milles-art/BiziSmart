<?php
use App\Models\{Business,Branch,Device,Plan,User,Product,ProductBatch,StockMovement,Sale,SaleItem};
use App\Services\{StaffRoleService,StockService,TillService};
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
uses(RefreshTenantDatabase::class);
beforeEach(function () {
    config(['tenancy.domain'=>'bizismart.test']); $this->context=app(TenantContext::class);
    $plan=Plan::create(['code'=>'margin','name'=>'Margin','price_monthly'=>0,'limits'=>['tills'=>5],'features'=>[]]);
    foreach (['alpha','beta'] as $label) {
        $shop=Business::create(['name'=>$label,'subdomain'=>$label,'plan_id'=>$plan->id,'owner_phone'=>'0700000001']); $this->{$label}=$shop;
        $this->context->run($shop,function () use ($label) {
            app(StaffRoleService::class)->provision(); $branch=Branch::create(['name'=>'Main']); $this->{$label.'Branch'}=$branch;
            $device=Device::create(['device_uid'=>'margin-test','name'=>'Margin test','status'=>'approved']);
            foreach (['owner','manager','cashier','stockkeeper'] as $i=>$role) {
                $u=User::create(['name'=>$role,'phone'=>'071000000'.$i,'pin_hash'=>'1234','must_change_pin'=>false,'branch_id'=>$branch->id]); app(StaffRoleService::class)->assign($u,$role);
                if ($role==='owner') { $u->totp_secret='JBSWY3DPEHPK3PXP'; $u->totp_confirmed_at=now(); $u->save(); }
                $token=$u->createToken('margin-test',['*'],now()->addDay()); $token->accessToken->device_id=$device->id; $token->accessToken->save(); $u->withAccessToken($token->accessToken);
                $this->{$label.ucfirst($role)}=$u; $this->{$label.ucfirst($role).'Token'}=$token->plainTextToken;
                if (in_array($role,['owner','manager','cashier'],true)) $this->{$label.ucfirst($role).'Till'}=app(TillService::class)->open($u,['operation_id'=>(string)Str::uuid(),'branch_id'=>$branch->publicId(),'opening_float'=>20000])['till'];
            }
            $p=Product::create(['name'=>'Margin milk','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>800,'buying_price'=>1000,'reorder_level_atoms'=>0,'is_active'=>true]); $this->{$label.'Product'}=$p;
            $this->{$label.'LossBatch'}=app(StockService::class)->receive($this->{$label.'Stockkeeper'},$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'LOSS','qty'=>'10','cost_price'=>1000,'expiry_date'=>null])['batch'];
            $this->{$label.'ProfitBatch'}=app(StockService::class)->receive($this->{$label.'Stockkeeper'},$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'PROFIT','qty'=>'10','cost_price'=>500,'expiry_date'=>null])['batch'];
            $w=Product::create(['name'=>'Margin rice','quantity_type'=>'weight','unit'=>'kg','selling_price'=>800,'buying_price'=>1000,'reorder_level_atoms'=>0,'is_active'=>true]); $this->{$label.'Weighted'}=$w;
            app(StockService::class)->receive($this->{$label.'Stockkeeper'},$w,['operation_id'=>(string)Str::uuid(),'batch_number'=>'WRICE','qty'=>'10','cost_price'=>1000,'expiry_date'=>null]);
        });
    }
});
afterEach(function () { $this->context->clear(); $this->travelBack(); \Illuminate\Database\Eloquent\Model::clearBootedModels(); });
function marginUrl(string $path='',string $shop='alpha'): string { return 'http://'.$shop.'.bizismart.test/api/v1/sales'.$path; }
function marginBody($test,string $role,array $items,array $extra=[]): array {
    $till=$test->{'alpha'.ucfirst($role).'Till'}['id'];
    $payload=[...['branch_id'=>$test->alphaBranch->publicId(),'till_id'=>$till,'items'=>$items],...$extra];
    $review=$test->withToken($test->{'alpha'.ucfirst($role).'Token'})->postJson(marginUrl('/preview'),$payload)->assertOk()->json('data');
    return [$review,[...$payload,'review_hash'=>$review['review_hash'],'cash_tendered'=>max(10000,$review['total'])]];
}
it('warns owners with exact batch costs without changing prices or blocking the sale',function () {
    [$review,$body]=marginBody($this,'owner',[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1']]);
    expect($review['total'])->toBe(800)->and($review['margin_warnings'])->toHaveCount(1)
        ->and($review['margin_warnings'][0])->toMatchArray(['code'=>'MARGIN_BELOW_COST','line_index'=>0,'product_id'=>$this->alphaProduct->publicId(),'batch_id'=>$this->alphaLossBatch->publicId(),'base_qty'=>'1','net_total'=>800,'cost_total'=>1000,'shortfall'=>200]);
    $sale=$this->withToken($this->alphaOwnerToken)->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(marginUrl(),$body)->assertCreated()
        ->assertJsonPath('data.total',800)->assertJsonPath('data.margin_warnings.0.shortfall',200)->json('data');
    $this->context->run($this->alpha,function () { expect(Sale::count())->toBe(1); expect(SaleItem::count())->toBe(1); expect(StockMovement::where('type','sale')->count())->toBe(1); expect(ProductBatch::where('batch_number','LOSS')->first()->qty_remaining_atoms)->toBe(9); });
});
it('warns managers without disclosing any cost figures',function () {
    [$review]=marginBody($this,'manager',[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1']]);
    expect($review['margin_warnings'])->toHaveCount(1);
    $warning=$review['margin_warnings'][0];
    expect($warning)->toMatchArray(['code'=>'MARGIN_BELOW_COST','batch_id'=>$this->alphaLossBatch->publicId(),'net_total'=>800])
        ->and($warning)->not->toHaveKeys(['cost_total','shortfall']);
});
it('gives cashiers no margin key at all while the sale still succeeds',function () {
    [$review,$body]=marginBody($this,'cashier',[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1']]);
    expect($review)->not->toHaveKey('margin_warnings');
    $this->withToken($this->alphaCashierToken)->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(marginUrl(),$body)->assertCreated()
        ->assertJsonMissingPath('data.margin_warnings')->assertJsonPath('data.total',800);
});
it('compares split-batch sales against each batch actual cost',function () {
    [$review]=marginBody($this,'owner',[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'11']]);
    expect($review['margin_warnings'])->toHaveCount(1);
    expect($review['margin_warnings'][0])->toMatchArray(['batch_id'=>$this->alphaLossBatch->publicId(),'base_qty'=>'10','net_total'=>8000,'cost_total'=>10000,'shortfall'=>2000]);
});
it('warns exact weighted quantities and stays silent at or above cost',function () {
    [$review]=marginBody($this,'owner',[['product_id'=>$this->alphaWeighted->publicId(),'qty'=>'1.5']]);
    expect($review['margin_warnings'])->toHaveCount(1);
    expect($review['margin_warnings'][0])->toMatchArray(['base_qty'=>'1.5','net_total'=>1200,'cost_total'=>1500,'shortfall'=>300]);
    $this->context->run($this->alpha,function () {
        $p=Product::create(['name'=>'Margin profit','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>1000,'buying_price'=>1000,'reorder_level_atoms'=>0,'is_active'=>true]);
        app(StockService::class)->receive($this->alphaStockkeeper,$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'EVEN','qty'=>'5','cost_price'=>1000,'expiry_date'=>null]);
        $this->evenProduct=$p;
    });
    [$even]=marginBody($this,'owner',[['product_id'=>$this->evenProduct->publicId(),'qty'=>'1']]);
    expect($even['margin_warnings'])->toBe([]);
});
it('warns on discounted nets and keeps previews read-only and tenant scoped',function () {
    $profitable=$this->context->run($this->alpha,function () {
        $p=Product::create(['name'=>'Margin safe','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>800,'buying_price'=>100,'reorder_level_atoms'=>0,'is_active'=>true]);
        app(StockService::class)->receive($this->alphaStockkeeper,$p,['operation_id'=>(string)Str::uuid(),'batch_number'=>'SAFE','qty'=>'5','cost_price'=>100,'expiry_date'=>null]);
        return $p;
    });
    [$safe]=marginBody($this,'owner',[['product_id'=>$profitable->publicId(),'qty'=>'1']]);
    expect($safe['margin_warnings'])->toBe([]);
    [$discounted]=marginBody($this,'manager',[['product_id'=>$this->alphaProduct->publicId(),'qty'=>'1','discount'=>400]],['discount_reason'=>'Clearance markdown']);
    expect($discounted['margin_warnings'])->toHaveCount(1)->and($discounted['margin_warnings'][0]['net_total'])->toBe(400);
    $this->context->run($this->alpha,function () {
        expect(ProductBatch::where('batch_number','LOSS')->first()->qty_remaining_atoms)->toBe(10);
        expect(ProductBatch::where('batch_number','PROFIT')->first()->qty_remaining_atoms)->toBe(10);
        expect(ProductBatch::where('batch_number','SAFE')->first()->qty_remaining_atoms)->toBe(5);
        expect(StockMovement::where('type','sale')->count())->toBe(0);
    });
    $this->withToken($this->betaOwnerToken)->postJson(marginUrl('/preview','beta'),['branch_id'=>$this->betaBranch->publicId(),'till_id'=>$this->betaOwnerTill['id'],'items'=>[['product_id'=>$this->betaProduct->publicId(),'qty'=>'1']]])
        ->assertOk()->assertJsonPath('data.margin_warnings.0.batch_id',$this->betaLossBatch->publicId());
});
