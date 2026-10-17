<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{SupervisorApproval,Business,Device,PersonalAccessToken,Product,ProductPackaging,Sale,SalePayment,TillSession,User};
use App\Support\{ExactMath,Money,Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\{AuthenticationException,Access\AuthorizationException};
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(private TenantContext $context, private StockService $stock, private TillService $tills, private TillAccessService $access, private DebtService $debts) {}

    public function preview(User $original, array $input): array
    {
        return DB::transaction(function () use ($original, $input) {
            $actor = $this->actor($original);
            $shop = Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $this->till($actor, $input);
            return $this->quote($actor, $shop, $input);
        }, 3);
    }

    /** Internal exchange quote only; ReturnService supplies the locked original-invoice reduction. */
    public function previewReplacement(User $actor, array $input, int $debtReduction): array {
        if (DB::transactionLevel() < 1) throw new \LogicException('Replacement quotes require a return transaction.');
        return $this->quote($actor, Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail(), $input, $debtReduction, false);
    }

    public function complete(User $original, array $input, string $key): array
    {
        return $this->completeInternal($original, $input, $key, true);
    }

    private function completeInternal(User $original, array $input, string $key, bool $conditionalPricing): array
    {
        $grant=isset($input['approval_token'])?SupervisorApproval::where('token_hash',hash('sha256',$input['approval_token']))->first():null;
        return DB::transaction(function () use ($original, $input, $key, $grant, $conditionalPricing) {
            User::whereIn('id',array_unique(array_filter([$original->id,$grant?->approver_id])))->orderBy('id')->lockForUpdate()->get();
            $actor = $this->actor($original);
            $shop = Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $key = strtolower($key);
            $fingerprint = SupervisorApprovalService::snapshotHash($input);
            $existing = Sale::where('operation_id', $key)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->cashier_id !== (int) $actor->id || (int) $existing->device_id !== (int) $actor->currentAccessToken()->device_id
                    || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new ApiException('OPERATION_ID_REUSED', 'This sale reference was already used with different details.', 409);
                }
                if(isset($input['payment_id']) && (int)$actor->branch_id !== (int)$existing->branch_id)throw new AuthorizationException;
                return ['sale'=>$existing, 'replayed'=>true];
            }
            $till = $this->till($actor, $input);
            $review = $this->quote($actor, $shop, $input, 0, $conditionalPricing);
            if (! hash_equals($review['review_hash'], $input['review_hash'])) { throw new ApiException('SALE_CHANGED', 'Prices or pack details changed. Review this sale again before taking payment.', 409); }
            $cash = Money::amount($input['cash_tendered']);
            $credit = $review['credit_amount'] ?? 0;
            $mobile = $review['mobile_amount'] ?? 0;
            $payment = null;
            if ($mobile > 0) $payment = app(MobilePaymentService::class)->consumeReview($actor, $input, $key);
            elseif (isset($input['payment_id'])) throw new ApiException('PAYMENT_MISMATCH', 'A mobile payment needs a mobile allocation.', 409);
            $cashAllocated = $review['total'] - $credit - $mobile;
            if ($cash < $cashAllocated) { throw new ApiException('PAYMENT_MISMATCH', 'Cash tendered does not cover the cash portion.', 400, 'cash_tendered'); }
            $write=function(?User $approver=null)use($actor,$till,$shop,$review,$input,$key,$fingerprint,$cash,$cashAllocated,$mobile,$credit,$payment){
            $sequence = Money::amount(ExactMath::add((int) $shop->sale_sequence, 1));
            $shop->sale_sequence = $sequence; $shop->save();
            $sale = Sale::create(['branch_id'=>$till->branch_id, 'cashier_id'=>$actor->id, 'device_id'=>$actor->currentAccessToken()->device_id,
                'till_session_id'=>$till->id, 'operation_id'=>$key, 'request_fingerprint'=>$fingerprint,
                'receipt_number'=>'RCP-'.now('Africa/Dar_es_Salaam')->year.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'status'=>'completed', 'subtotal'=>$review['subtotal'], 'discount'=>$review['discount'], 'total'=>$review['total'], 'discount_approved_by'=>$approver?->id, 'discount_reason'=>$approver?trim($input['discount_reason']):null, 'vat_amount'=>$review['vat_amount'],
                'vat_rate'=>$review['vat_rate'], 'cash_tendered'=>$cash, 'change_given'=>$cash-$cashAllocated, 'lines'=>$review['lines'],
                'loyalty_redemption'=>$review['loyalty_redemption']??null,'customer_id'=>isset($review['customer_id']) ? (new \App\Models\Customer)->resolveRouteBinding($review['customer_id'])->id : null]);
            foreach ($review['lines'] as $index=>$line) {
                $product = (new Product)->resolveRouteBinding($line['product_id']);
                $atoms = Quantity::parse($line['base_qty'], $line['quantity_type'] === 'weight');
                $this->stock->allocateSale($actor, $product, $sale, $atoms, $index, $line['unit_price'],$line['pricing_allocations']??null);
            }
            app(PricingService::class)->consume($actor,$sale,$review['lines']);
            if ($cashAllocated > 0 || $review['total'] === 0) { SalePayment::create(['sale_id'=>$sale->id, 'method'=>'cash', 'amount'=>$cashAllocated]); }
            if ($payment) {
                SalePayment::create(['sale_id'=>$sale->id,'method'=>'mpesa','amount'=>$mobile,'mobile_payment_id'=>$payment->id]);
                $payment->sale_id=$sale->id; $payment->status='allocated'; $payment->save();
            }
            if ($credit > 0) {
                SalePayment::create(['sale_id'=>$sale->id,'method'=>'credit','amount'=>$credit]);
                $this->debts->createCredit((new \App\Models\Customer)->resolveRouteBinding($review['customer_id']),$sale,$credit);
            }
            if ($cashAllocated > 0) { $this->tills->recordCash($actor, $till, 'cash_sale', $cashAllocated, $key); }
            app(IncentiveService::class)->recordSale($actor,$sale);
            activity('sales')->performedOn($sale)->causedBy($actor)->event('sale_completed')
                    ->withProperties(['sale_id'=>$sale->publicId(), 'total'=>$sale->total, 'till_id'=>$till->publicId()])->log('Sale completed');
            return ['sale'=>$sale, 'replayed'=>false];
            };
            if($this->hasDiscount($input)){
                if(!isset($input['approval_token']))throw new ApiException('APPROVAL_REQUIRED','Request supervisor approval for this discount.',403);
                $snapshot=$review;unset($snapshot['review_hash']);
                return $this->withMarginWarnings($review,app(SupervisorApprovalService::class)->execute($actor,$input['approval_token'],'give_discount',$key,$snapshot,$write));
            }
            if(isset($input['approval_token']))throw new ApiException('VALIDATION_ERROR','This sale has no discount to approve.',422);
            return $this->withMarginWarnings($review,$write());
        }, 3);
    }

    /** Trusted ReturnService boundary; JSON input can never select this mode. */
    public function completeReplacement(User $actor, array $input, string $key): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Replacement sales require their approved return transaction.');
        return $this->completeInternal($actor, $input, $key, false);
    }

    public function read(User $actor, Sale $sale): Sale
    {
        $actor->assertCurrentBusiness(); $sale->assertCurrentBusiness();
        if ((int) $sale->cashier_id !== (int) $actor->id || (int) $sale->device_id !== (int) $actor->currentAccessToken()->device_id) { throw new AuthorizationException; }
        return $sale;
    }

    private function quote(User $actor, Business $shop, array $input, int $debtReduction = 0, bool $conditionalPricing = true): array
    {
        try {
            $products = [];
            foreach ($input['items'] as $entry) {
                $p = (new Product)->resolveRouteBinding($entry['product_id']);
                if (! $p) { throw new ApiException('NOT_FOUND', 'A requested product was not found.', 404); }
                $products[$p->id] = $p;
            }
            ksort($products, SORT_NUMERIC);
            foreach ($products as $p) { $p->refreshForUpdate(); if ($p->trashed() || ! $p->is_active) { throw new ApiException('PRODUCT_INACTIVE', 'A product is no longer available for sale.', 409); } }
            $byPublic = []; foreach ($products as $p) { $byPublic[$p->publicId()] = $p; }
            $lines = []; $total = 0;
            foreach ($input['items'] as $entry) {
                $p = $byPublic[$entry['product_id']]; $pack = null;
                if (isset($entry['packaging_id'])) {
                    $pack = (new ProductPackaging)->resolveRouteBindingQuery(ProductPackaging::where('product_id', $p->id), $entry['packaging_id'])->lockForUpdate()->first();
                    if (! $pack || $pack->archived_at || ! $pack->is_active) { throw new ApiException('PACKAGING_VERSION_CHANGED', 'This pack changed. Refresh the catalogue and select its current version.', 409); }
                }
                $weighted = $p->quantity_type === 'weight';
                $entered = Quantity::parse($entry['qty'], $weighted && ! $pack);
                if ($entered <= 0) { throw new \InvalidArgumentException('Enter a positive quantity.'); }
                $qty = Quantity::format($entered, $weighted && ! $pack);
                $atoms = $pack ? Quantity::packageAtoms($qty, $pack->conversion_atoms) : $entered;
                $price = $pack ? $pack->selling_price : $p->selling_price;
                $charge = Money::lineTotal($entered, $price, $weighted && ! $pack);
                $total = Money::amount(ExactMath::add($total, $charge));
                $lines[] = ['product_id'=>$p->publicId(), 'name'=>$p->name, 'quantity_type'=>$p->quantity_type, 'unit'=>$p->unit,
                    'qty'=>$qty, 'base_qty'=>Quantity::format($atoms, $weighted), 'packaging_id'=>$pack?->publicId(),
                    'packaging_name'=>$pack?->name, 'packaging_version'=>$pack?->version, 'conversion_atoms'=>(string) ($pack?->conversion_atoms ?? ($weighted ? 1000 : 1)),
                    'unit_price'=>$price, 'discount'=>Money::amount($entry['discount']??0), 'line_total'=>$charge];
            }
            $lines=app(PricingService::class)->apply($actor,$lines,$input['branch_id'],$conditionalPricing);
            $manualCart=Money::amount($input['discount']??0);
            $baseDiscounted=app(SaleDiscountCalculator::class)->apply($lines,$manualCart);
            $redemption=app(IncentiveService::class)->previewRedemption($actor,$input,$baseDiscounted['total']);
            $cartDiscount=Money::amount(ExactMath::add($manualCart,$redemption['discount_amount']??0));
            $discounted=app(SaleDiscountCalculator::class)->apply($lines,$cartDiscount);
            if($redemption){$position=0;$last=0;foreach($discounted['lines'] as &$priced){$position=ExactMath::add($position,$priced['cart_discount']);$next=ExactMath::roundProportion($redemption['discount_amount'],$position,$cartDiscount);$priced['loyalty_discount']=$next-$last;$last=$next;$priced['cart_discount']-=$priced['loyalty_discount'];}unset($priced);}
            $lines=$discounted['lines'];$total=$discounted['total'];
            if($this->hasDiscount($input)&&(!isset($input['discount_reason'])||trim($input['discount_reason'])===''))throw new \InvalidArgumentException('Enter the reason for this discount.');
            $rate = $shop->vat_rate;
            if (! is_int($rate) || $rate < 0 || $rate > 100) { throw new \InvalidArgumentException('The shop VAT rate needs configuration.'); }
            $result = ['branch_id'=>$input['branch_id'], 'till_id'=>$input['till_id'], 'lines'=>$lines, 'subtotal'=>$discounted['subtotal'], 'discount'=>$discounted['discount'],
                'total'=>$total, 'vat_rate'=>$rate, 'vat_amount'=>Money::amount(ExactMath::roundProductRatio($total, $rate, 100+$rate)),
                ...$this->debts->saleReview($actor,$input,$total,$debtReduction)];
            if($redemption)$result=[...$result,'loyalty_redemption'=>$redemption,'manual_cart_discount'=>$manualCart];
            if($this->hasDiscount($input))$result['discount_reason']=trim($input['discount_reason']);
            $mobile = Money::amount($input['mobile_amount'] ?? 0);
            if ($mobile > ($result['cash_allocated'] ?? $total)) throw new ApiException('PAYMENT_MISMATCH', 'Mobile amount exceeds the amount left after credit.', 422);
            if ($mobile > 0) { $result['mobile_amount']=$mobile; $result['cash_allocated']=($result['cash_allocated']??$total)-$mobile; }
            $warnings = app(MarginAlertService::class)->warn($actor, $lines);
            if ($warnings !== null) { $result['margin_warnings'] = $warnings; }
            return [...$result, 'review_hash'=>SupervisorApprovalService::snapshotHash($result)];
        } catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422); }
    }

    /** Replays the preview's margin warnings on a fresh commit; replays of saved sales omit them. */
    private function withMarginWarnings(array $review, mixed $result): mixed
    {
        if (is_array($result) && array_key_exists('margin_warnings', $review)) {
            $result['margin_warnings'] = $review['margin_warnings'];
        }
        return $result;
    }

    public function hasDiscount(array $input):bool {
        if(($input['discount']??0)>0||($input['loyalty_points']??0)>0)return true;
        foreach($input['items']??[] as $line)if(($line['discount']??0)>0)return true;
        return false;
    }

    private function till(User $actor, array $input): TillSession
    {
        $till = (new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch','cashier']), $input['till_id'])->lockForUpdate()->first();
        if (! $till) { throw new ApiException('TILL_REQUIRED', 'Open your till before selling.', 400); }
        if (! $this->access->canManage($actor, $till->branch, $till->cashier) || $till->branch->publicId() !== $input['branch_id'] || ! $till->branch->is_active) { throw new AuthorizationException; }
        if ($till->status !== 'open') { throw new ApiException('TILL_CLOSED', 'Open a till before selling.', 409); }
        return $till;
    }

    private function actor(User $original): User
    {
        $original->assertCurrentBusiness();
        $actor = User::whereKey($original->id)->lockForUpdate()->first();
        $token = $original->currentAccessToken();
        $current = $token ? PersonalAccessToken::whereKey($token->id)->where('tokenable_id', $original->id)->lockForUpdate()->first() : null;
        $device = $current?->device_id ? Device::whereKey($current->device_id)->lockForUpdate()->first() : null;
        if (! $actor || ! $actor->is_active || $actor->must_change_pin || $actor->getRoleNames()->count() !== 1
            || ($actor->hasRole('owner') && ! $actor->ownerAuthenticationReady()) || ! $current || $current->expires_at?->isPast() || $device?->status !== 'approved') { throw new AuthenticationException; }
        if (! $actor->can('sell')) { throw new AuthorizationException; }
        return $actor->withAccessToken($current);
    }
}
