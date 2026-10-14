<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business,Customer,CustomerPayment,DebtAllocation,Sale,SaleDebt,TillSession,User};
use App\Support\{ExactMath,Money};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DebtService
{
    public function __construct(private TenantContext $context, private FinancialActorService $actors,
        private TillService $tills, private TillAccessService $access) {}

    public function saleReview(User $actor, array $input, int $total, int $returnReduction = 0): array
    {
        $credit = Money::amount($input['credit_amount'] ?? 0);
        if ($credit > $total) { throw new ApiException('PAYMENT_MISMATCH','Credit cannot exceed the sale total.',400,'credit_amount'); }
        if (! isset($input['customer_id'])) {
            if ($credit > 0) { throw new ApiException('CUSTOMER_REQUIRED','Choose a customer for credit.',422,'customer_id'); }
            return [];
        }
        if (! $actor->can('manage_customers')) { throw new AuthorizationException; }
        $customer = $this->customer($input['customer_id']);
        $balance = Money::amount($customer->outstanding_balance - $returnReduction);
        if ($credit > 0 && ExactMath::add($balance,$credit) > $customer->credit_limit) {
            throw new ApiException('CREDIT_LIMIT_EXCEEDED','This credit amount exceeds the customer limit.',400,'credit_amount');
        }
        return ['customer_id'=>$customer->publicId(),'customer_name'=>$customer->name,'credit_amount'=>$credit,
            'cash_allocated'=>$total-$credit,'customer_balance'=>$balance,'credit_limit'=>$customer->credit_limit];
    }

    public function createCredit(Customer $customer, Sale $sale, int $amount): void
    {
        $customer->assertCurrentBusiness(); $sale->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $amount <= 0 || (int)$sale->customer_id !== (int)$customer->id) { throw new \LogicException('Credit needs its customer sale transaction.'); }
        $customer->refreshForUpdate();
        $balance = ExactMath::add($customer->outstanding_balance,$amount);
        if ($balance > $customer->credit_limit) { throw new ApiException('CREDIT_LIMIT_EXCEEDED','This credit amount exceeds the customer limit.',400); }
        SaleDebt::create(['customer_id'=>$customer->id,'sale_id'=>$sale->id,'original_amount'=>$amount,'remaining_amount'=>$amount]);
        $customer->outstanding_balance = Money::amount($balance); $customer->save();
    }

    /** ReturnService holds business/customer/invoice locks and appends the reduction in its return journal. */
    public function reduceForReturn(Sale $sale, int $amount): void {
        $sale->assertCurrentBusiness(); Money::amount($amount);
        if (DB::transactionLevel() < 1) throw new \LogicException('Debt reductions require a return transaction.');
        if ($amount === 0) return;
        $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
        $debt = SaleDebt::where('sale_id',$sale->id)->lockForUpdate()->firstOrFail();
        if ($amount > $debt->remaining_amount || $amount > $customer->outstanding_balance) throw new ApiException('DEBT_CHANGED','Review the return again.',409);
        $debt->remaining_amount -= $amount; $debt->save();
        $customer->outstanding_balance -= $amount; $customer->save();
    }

    public function overview(Customer $customer): array
    {
        $customer->assertCurrentBusiness();
        return DB::transaction(function () use ($customer) {
            $customer->refreshForUpdate();
            $aging = ['0_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0]; $sales = [];
            foreach (SaleDebt::with('sale')->where('customer_id',$customer->id)->where('remaining_amount','>',0)->orderBy('id')->get() as $debt) {
                $days = max(0,(int)$debt->sale->created_at->timezone('Africa/Dar_es_Salaam')->startOfDay()->diffInDays(now('Africa/Dar_es_Salaam')->startOfDay(),false));
                $bucket = $days <= 30 ? '0_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : '90_plus'));
                $aging[$bucket] = Money::amount(ExactMath::add($aging[$bucket],$debt->remaining_amount));
                $sales[] = ['sale_id'=>$debt->sale->publicId(),'receipt_number'=>$debt->sale->receipt_number,'created_at'=>$debt->sale->created_at->toISOString(),
                    'original_amount'=>$debt->original_amount,'remaining_amount'=>$debt->remaining_amount];
            }
            return ['customer_id'=>$customer->publicId(),'outstanding_balance'=>$customer->outstanding_balance,'credit_limit'=>$customer->credit_limit,'aging'=>$aging,'open_sales'=>$sales];
        });
    }

    public function preview(User $original, Customer $customer, array $input): array
    {
        return DB::transaction(function () use ($original,$customer,$input) {
            $actor = $this->lock($original,$customer); $till = $this->till($actor,$input['till_id']);
            return $this->review($customer,$till,$input);
        },3);
    }

    public function collect(User $original, Customer $customer, array $input, string $key): array
    {
        return DB::transaction(function () use ($original,$customer,$input,$key) {
            $actor = $this->lock($original,$customer); $key = strtolower($key);
            $fingerprint = SupervisorApprovalService::snapshotHash(['customer_id'=>$customer->publicId(),...$input]);
            $existing = CustomerPayment::where('operation_id',$key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->customer_id !== $customer->id || $existing->received_by !== $actor->id || $existing->device_id !== $actor->currentAccessToken()->device_id
                    || ! hash_equals($existing->request_fingerprint,$fingerprint)) { throw new ApiException('OPERATION_ID_REUSED','This payment reference was already used with different details.',409); }
                return ['payment'=>$existing,'replayed'=>true];
            }
            $till = $this->till($actor,$input['till_id']); $review = $this->review($customer,$till,$input);
            if (! hash_equals($review['review_hash'],$input['review_hash'])) { throw new ApiException('DEBT_CHANGED','These invoice balances changed. Review the collection again.',409); }
            $mobile=null;
            if($input['method']==='mpesa')$mobile=app(MobilePaymentService::class)->consumeCollection($actor,$customer,$input,$key);
            elseif(isset($input['payment_id']))throw new ApiException('PAYMENT_MISMATCH','Cash collections cannot use a mobile payment.',409);
            $payment = CustomerPayment::create(['customer_id'=>$customer->id,'received_by'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,
                'till_session_id'=>$till->id,'operation_id'=>$key,'request_fingerprint'=>$fingerprint,'amount'=>$review['amount'],'method'=>$input['method'],'mobile_payment_id'=>$mobile?->id,'allocations'=>$review['allocations']]);
            foreach ($review['allocations'] as $allocation) {
                $sale = (new Sale)->resolveRouteBinding($allocation['sale_id']);
                $debt = SaleDebt::where('customer_id',$customer->id)->where('sale_id',$sale->id)->lockForUpdate()->firstOrFail();
                $debt->remaining_amount -= $allocation['amount']; $debt->save();
                DebtAllocation::create(['customer_id'=>$customer->id,'customer_payment_id'=>$payment->id,'sale_debt_id'=>$debt->id,'amount'=>$allocation['amount']]);
            }
            $customer->outstanding_balance -= $review['amount']; $customer->save();
            if($mobile){$mobile->customer_payment_id=$payment->id;$mobile->status='allocated';$mobile->save();}else $this->tills->recordCash($actor,$till,'cash_debt_payment',$review['amount'],$key);
            app(IncentiveService::class)->recordCollection($payment);
            activity('debts')->performedOn($payment)->causedBy($actor)->event('debt_collected')
                ->withProperties(['payment_id'=>$payment->publicId(),'customer_id'=>$customer->publicId(),'amount'=>$payment->amount])->log('Customer debt collection recorded');
            return ['payment'=>$payment,'replayed'=>false];
        },3);
    }

    private function review(Customer $customer, TillSession $till, array $input): array
    {
        $amount = Money::amount($input['amount']);
        if ($amount === 0) { throw new ApiException('VALIDATION_ERROR','Enter a positive collection amount.',422,'amount'); }
        if ($amount > $customer->outstanding_balance) { throw new ApiException('OVERPAYMENT','The payment exceeds the customer balance.',400,'amount'); }
        $sum = 0; $allocations = []; $seen = [];
        foreach ($input['allocations'] as $entry) {
            if (isset($seen[$entry['sale_id']])) { throw new ApiException('VALIDATION_ERROR','Choose each invoice once.',422,'allocations'); }
            $seen[$entry['sale_id']] = true;
            $sale = (new Sale)->resolveRouteBinding($entry['sale_id']);
            $debt = $sale ? SaleDebt::where('sale_id',$sale->id)->where('customer_id',$customer->id)->lockForUpdate()->first() : null;
            if (! $debt) { throw new ApiException('NOT_FOUND','A selected invoice was not found for this customer.',404); }
            $allocated = Money::amount($entry['amount']);
            if ($allocated === 0 || $allocated > $debt->remaining_amount) { throw new ApiException('OVERPAYMENT','An allocation exceeds its invoice balance.',400,'allocations'); }
            if ($allocated > $amount-$sum) { throw new ApiException('PAYMENT_MISMATCH','Invoice allocations exceed the payment amount.',400,'allocations'); }
            $sum = ExactMath::add($sum,$allocated);
            $allocations[] = ['sale_id'=>$sale->publicId(),'amount'=>$allocated,'expected_balance'=>$debt->remaining_amount];
        }
        if ($sum !== $amount) { throw new ApiException('PAYMENT_MISMATCH','Invoice allocations must equal the payment amount.',400,'allocations'); }
        $result = ['customer_id'=>$customer->publicId(),'till_id'=>$till->publicId(),'amount'=>$amount,'method'=>$input['method'],
            'outstanding_balance'=>$customer->outstanding_balance,'balance_after'=>$customer->outstanding_balance-$amount,'allocations'=>$allocations];
        return [...$result,'review_hash'=>SupervisorApprovalService::snapshotHash($result)];
    }

    private function lock(User $original, Customer $customer): User
    {
        $customer->assertCurrentBusiness(); $actor = $this->actors->lock($original,'manage_customers');
        Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail(); $customer->refreshForUpdate();
        return $actor;
    }
    private function customer(string $public): Customer
    {
        return (new Customer)->resolveRouteBindingQuery(Customer::query(),$public)->lockForUpdate()->firstOrFail();
    }
    private function till(User $actor, string $public): TillSession
    {
        $till = (new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch','cashier']),$public)->lockForUpdate()->first();
        if (! $till) { throw new ApiException('TILL_REQUIRED','Open your till before collecting cash.',400); }
        if (! $this->access->canManage($actor,$till->branch,$till->cashier) || ! $till->branch->is_active) { throw new AuthorizationException; }
        if ($till->status !== 'open') { throw new ApiException('TILL_CLOSED','This till is closed.',409); }
        return $till;
    }
}
