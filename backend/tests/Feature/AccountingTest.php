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
    $this->travelTo(now()->setDate(2026,10,13)->setTime(9,0));
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
    $this->withToken($this->alphaOwnerToken);
});
afterEach(function(){$this->context->clear();$this->travelBack();});

function accountingUrl(string $path,string $shop='alpha'):string{return 'http://'.$shop.'.bizismart.test/api/v1/'.$path;}
function accountingExpense($t,int $amount=1000):array{$body=['operation_id'=>(string)Str::uuid(),'amount'=>$amount,'category'=>'Rent','description'=>'Private rent receipt','incurred_on'=>'2026-10-12'];$review=$t->postJson(accountingUrl('expenses/preview'),$body)->assertOk()->json('data.review_hash');return $t->postJson(accountingUrl('expenses'),[...$body,'review_hash'=>$review])->assertCreated()->json('data.expense');}
function accountingTill($t):array{return $t->postJson(accountingUrl('till-sessions/open'),['operation_id'=>(string)Str::uuid(),'branch_id'=>$t->alphaBranch->publicId(),'opening_float'=>20000])->assertCreated()->json('data.till');}
function accountingPay($t,array $expense,array $body):array{
 $url=accountingUrl('expenses/'.$expense['id'].'/payments');
 $review=$t->postJson($url.'/preview',$body)->assertOk()->json('data');
 return $t->postJson($url,[...$body,'review_hash'=>$review['review_hash']])->assertOk()->json('data.expense');
}
it('journals an expense once with encrypted details and refuses changed replay',function(){
 $body=['operation_id'=>(string)Str::uuid(),'amount'=>1000,'category'=>'Rent','description'=>'Private rent receipt','incurred_on'=>'2026-10-12'];
 $this->postJson(accountingUrl('expenses'),$body)->assertUnprocessable();$review=$this->postJson(accountingUrl('expenses/preview'),$body)->assertOk()->json('data.review_hash');$body['review_hash']=$review;
 $id=$this->postJson(accountingUrl('expenses'),$body)->assertCreated()->json('data.expense.id');
 $this->postJson(accountingUrl('expenses'),$body)->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.expense.id',$id);
 $this->postJson(accountingUrl('expenses'),[...$body,'amount'=>1001])->assertConflict();
 $this->context->run($this->alpha,function(){expect(\App\Models\Expense::count())->toBe(1);expect(\App\Models\Expense::firstOrFail()->getRawOriginal('description'))->not->toContain('Private rent receipt');});
});
it('separates external payment cash flow from expense accrual and requires actual refund records',function(){
 $e=accountingExpense($this);
 $e=accountingPay($this,$e,['operation_id'=>(string)Str::uuid(),'kind'=>'payment','source'=>'bank','amount'=>1000,'paid_on'=>'2026-10-13','reference'=>'BANK-1']);
 expect($e['balance'])->toBe(0);
 $reverse=['operation_id'=>(string)Str::uuid(),'amount'=>400,'reason'=>'Supplier correction','reversed_on'=>'2026-10-13'];$review=$this->postJson(accountingUrl('expenses/'.$e['id'].'/reverse/preview'),$reverse)->assertOk()->json('data.review_hash');$e=$this->postJson(accountingUrl('expenses/'.$e['id'].'/reverse'),[...$reverse,'review_hash'=>$review])->assertOk()->json('data.expense');
 expect($e['refund_due'])->toBe(400)->and($e['paid_amount'])->toBe(1000);
 $e=accountingPay($this,$e,['operation_id'=>(string)Str::uuid(),'kind'=>'refund','source'=>'bank','amount'=>400,'payment_id'=>$e['payments'][0]['id'],'paid_on'=>'2026-10-13','reference'=>'BANK-REFUND-1']);
 expect($e['refund_due'])->toBe(0)->and($e['paid_amount'])->toBe(600);
 $this->getJson(accountingUrl('reports/summary?from=2026-10-12&to=2026-10-13'))->assertOk()->assertJsonPath('data.summary.net_expenses',600)->assertJsonPath('data.summary.net_profit',-600)->assertJsonPath('data.summary.net_cashflow',-600);
 $this->getJson(accountingUrl('reports/summary?from=2026-10-13&to=2026-10-13'))->assertOk()->assertJsonPath('data.summary.expenses',0)->assertJsonPath('data.summary.expense_reversals',400);
});
it('rejects excess settlement and requires approval for drawer-funded expenses without changing cash',function(){
 $e=accountingExpense($this);$t=accountingTill($this);
 $body=['operation_id'=>(string)Str::uuid(),'kind'=>'payment','source'=>'till','amount'=>1000,'paid_on'=>'2026-10-13','till_id'=>$t['id']];
 $url=accountingUrl('expenses/'.$e['id'].'/payments');$r=$this->postJson($url.'/preview',$body)->assertOk()->assertJsonPath('data.approval_required',true)->json('data');
 $this->postJson($url,[...$body,'review_hash'=>$r['review_hash']])->assertForbidden()->assertJsonPath('error.code','APPROVAL_REQUIRED');
 $this->postJson($url.'/preview',[...$body,'amount'=>1001])->assertUnprocessable();
 $this->getJson(accountingUrl('till-sessions/current'))->assertOk()->assertJsonPath('data.till.expected_cash',20000);
});
it('deducts a handover once and blocks closure until the nominated manager acknowledges',function(){
 $this->withToken($this->alphaCashierToken);$t=accountingTill($this);$body=['operation_id'=>(string)Str::uuid(),'till_id'=>$t['id'],'receiver_id'=>$this->alphaManager->publicId(),'amount'=>5000,'reason'=>'Safe handover'];
 $r=$this->postJson(accountingUrl('cash-drops/preview'),$body)->assertOk()->json('data');
 $d=$this->postJson(accountingUrl('cash-drops'),[...$body,'review_hash'=>$r['review_hash']])->assertCreated()->json('data.drop');
 $this->postJson(accountingUrl('cash-drops'),[...$body,'review_hash'=>$r['review_hash']])->assertOk()->assertJsonPath('data.replayed',true);
 $this->getJson(accountingUrl('till-sessions/current'))->assertJsonPath('data.till.expected_cash',15000);
 $close=['operation_id'=>(string)Str::uuid(),'till_id'=>$t['id'],'counted_cash'=>15000];
 $this->postJson(accountingUrl('till-sessions/current/close/preview'),$close)->assertConflict();
 $this->withToken($this->alphaManagerToken);$ack=['operation_id'=>(string)Str::uuid()];
 $this->postJson(accountingUrl('cash-drops/'.$d['id'].'/acknowledge'),$ack)->assertOk();
 $other=$this->context->run($this->alpha,fn()=>Branch::create(['name'=>'Other']));
 $this->context->run($this->alpha,function()use($other){$this->alphaManager->branch_id=$other->id;$this->alphaManager->save();});
 $this->postJson(accountingUrl('cash-drops/'.$d['id'].'/acknowledge'),$ack)->assertForbidden();
 $this->withToken($this->alphaCashierToken)->postJson(accountingUrl('till-sessions/current/close/preview'),$close)->assertOk()->assertJsonPath('data.expected_cash',15000);
 $this->withToken($this->alphaOwnerToken)->getJson(accountingUrl('reports/summary?from=2026-10-13&to=2026-10-13'))->assertOk()->assertJsonPath('data.summary.net_cashflow',0)->assertJsonPath('data.summary.net_profit',0);
});
it('fails closed for managers and other tenants on expense and financial report resources',function(){
 $e=accountingExpense($this);
 $this->withToken($this->alphaManagerToken)->getJson(accountingUrl('expenses'))->assertForbidden();
 $this->getJson(accountingUrl('reports/summary?from=2026-10-13&to=2026-10-13'))->assertForbidden();
 $this->withToken($this->betaOwnerToken)->postJson(accountingUrl('expenses/'.$e['id'].'/reverse','beta'),['operation_id'=>(string)Str::uuid(),'amount'=>1,'reason'=>'Cross tenant','reversed_on'=>'2026-10-13'])->assertNotFound();
 $this->getJson(accountingUrl('expenses','beta'))->assertOk()->assertJsonPath('meta.total',0);
});

it('attributes sale and partial return revenue VAT and original batch costs to their own event dates',function(){
 $this->context->run($this->alpha,function(){$this->alpha->vat_rate=18;$this->alpha->save();});
 $product=$this->context->run($this->alpha,fn()=>app(\App\Services\ProductService::class)->save($this->alphaOwner,['name'=>'Report item','barcode'=>'REPORT-ITEM','quantity_type'=>'counted','unit'=>'item','buying_price'=>500,'selling_price'=>1180,'reorder_level'=>'0','tracks_expiry'=>false]));
 $this->context->run($this->alpha,fn()=>app(\App\Services\StockService::class)->receive($this->alphaOwner,$product,['operation_id'=>(string)Str::uuid(),'qty'=>'3','cost_price'=>500,'batch_number'=>'REPORT-1']));
 $this->withToken($this->alphaCashierToken);$t=accountingTill($this);$body=['branch_id'=>$this->alphaBranch->publicId(),'till_id'=>$t['id'],'items'=>[['product_id'=>$product->publicId(),'qty'=>'2']]];
 $r=$this->postJson(accountingUrl('sales/preview'),$body)->assertOk()->json('data.review_hash');
 $sale=$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson(accountingUrl('sales'),[...$body,'review_hash'=>$r,'cash_tendered'=>2360])->assertCreated()->json('data');
 $this->travel(1)->days();$return=['operation_id'=>(string)Str::uuid(),'sale_id'=>$sale['id'],'till_id'=>$t['id'],'reason'=>'One item returned','items'=>[['line_index'=>0,'qty'=>'1','disposition'=>'sellable']]];
 $r=$this->postJson(accountingUrl('returns/preview'),$return)->assertOk()->json('data.review_hash');
 $grant=$this->postJson(accountingUrl('auth/supervisor-check'),['phone'=>'0700000002','pin'=>'1234','permission'=>'refund','operation_id'=>$return['operation_id'],'payload_hash'=>$r])->assertOk()->json('data.approval_token');
 $this->withHeader('Idempotency-Key',$return['operation_id'])->postJson(accountingUrl('returns'),[...$return,'review_hash'=>$r,'approval_token'=>$grant])->assertCreated();
 $this->withToken($this->alphaOwnerToken);
 $this->getJson(accountingUrl('reports/summary?from=2026-10-13&to=2026-10-13'))->assertOk()->assertJsonPath('data.daily.0.net_sales_gross',2360)->assertJsonPath('data.summary.sales_gross',2360)->assertJsonPath('data.summary.sales_vat',360)->assertJsonPath('data.summary.net_cogs',1000)->assertJsonPath('data.summary.gross_profit',1000)->assertJsonPath('data.summary.net_cashflow',2360);
 $this->getJson(accountingUrl('reports/summary?from=2026-10-14&to=2026-10-14'))->assertOk()->assertJsonPath('data.daily.0.net_sales_gross',-1180)->assertJsonPath('data.summary.refunds_gross',1180)->assertJsonPath('data.summary.refunds_vat',180)->assertJsonPath('data.summary.net_cogs',-500)->assertJsonPath('data.summary.gross_profit',-500)->assertJsonPath('data.summary.net_cashflow',-1180)->assertJsonPath('data.stock.recorded_value',1000);
});
it('binds expense reversal confirmation to the current journal balances and exact immutable replay',function(){
 $expense=accountingExpense($this);$url=accountingUrl('expenses/'.$expense['id'].'/reverse');$body=['operation_id'=>(string)Str::uuid(),'amount'=>400,'reason'=>'Reviewed adjustment','reversed_on'=>'2026-10-13'];
 $this->postJson($url,$body)->assertUnprocessable();$review=$this->postJson($url.'/preview',$body)->assertOk()->json('data.review_hash');
 accountingPay($this,$expense,['operation_id'=>(string)Str::uuid(),'kind'=>'payment','source'=>'bank','amount'=>500,'paid_on'=>'2026-10-13','reference'=>'NEW-PAID-BALANCE']);
 $this->postJson($url,[...$body,'review_hash'=>$review])->assertConflict()->assertJsonPath('error.code','EXPENSE_CHANGED');
 $this->context->run($this->alpha,fn()=>expect(\App\Models\ExpenseReversal::count())->toBe(0));
 $this->postJson($url.'/preview',[...$body,'amount'=>'400'])->assertUnprocessable();$fresh=$this->postJson($url.'/preview',$body)->assertOk()->json('data.review_hash');$commit=[...$body,'review_hash'=>$fresh];
 $this->postJson($url,$commit)->assertOk()->assertJsonPath('data.expense.effective_amount',600)->assertJsonPath('data.expense.balance',100);$this->postJson($url,$commit)->assertOk()->assertJsonPath('data.replayed',true);
 $this->postJson($url,[...$commit,'review_hash'=>str_repeat('0',64)])->assertConflict()->assertJsonPath('error.code','OPERATION_ID_REUSED');
});
