<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,Branch,User,Product,ProductPackaging,Customer,HeldCart,HeldCartOperation};
use App\Support\{Quantity,Money};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Holding stores an encrypted draft, never reserves stock, till cash or approval. */
class HoldService {
    public function __construct(private TenantContext $context,private FinancialActorService $actors) {}
    public function list(User $original,string $branchId): array {
        return DB::transaction(function() use($original,$branchId) {
            $actor=$this->actors->lock($original,'sell');
            Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $branch=$this->branch($actor,$branchId);
            return HeldCart::where('branch_id',$branch->id)->where('status','held')->where('expires_at','>',now())
                ->orderBy('created_at')->limit(100)->get()->map(fn($cart)=>$this->resource($cart))->all();
        },3);
    }
    public function save(User $original,array $input,string $key): array {
        return $this->operation($original,'save',$input,$key,function(User $actor) use($input) {
            $branch=$this->branch($actor,$input['branch_id']);
            $draft=$this->draft($input);
            $cart=HeldCart::create(['branch_id'=>$branch->id,'actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,
                'draft'=>$draft,'expires_at'=>now()->addHours(24)]);
            return [$cart,$this->resource($cart)];
        });
    }
    public function claim(User $original,string $id,string $action,string $key): array {
        if (!in_array($action,['resume','delete'],true)) throw new \LogicException('Invalid hold action.');
        return $this->operation($original,$action,['held_cart_id'=>$id],$key,function(User $actor) use($id,$action) {
            $cart=(new HeldCart)->resolveRouteBindingQuery(HeldCart::query(),$id)->lockForUpdate()->first();
            if (!$cart) throw new ApiException('NOT_FOUND','Held cart not found.',404);
            $this->branch($actor,Branch::findOrFail($cart->branch_id)->publicId());
            if ($cart->status!=='held') throw new ApiException('HOLD_UNAVAILABLE','This held cart has already been resumed or deleted.',409);
            if ($cart->expires_at->isPast()) throw new ApiException('HOLD_EXPIRED','This held cart has expired.',409);
            // Resume validates identifiers/quantity; normal sale preview reprices and checks stock/credit.
            if ($action==='resume') $this->draft($cart->draft);
            $cart->status=$action==='resume'?'resumed':'deleted'; $cart->save();
            return [$cart,$this->resource($cart)];
        });
    }
    private function operation(User $original,string $action,array $body,string $key,\Closure $perform): array {
        return DB::transaction(function() use($original,$action,$body,$key,$perform) {
            $actor=$this->actors->lock($original,'sell');
            Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
            $key=strtolower($key); $hash=SupervisorApprovalService::snapshotHash(['action'=>$action,'body'=>$body]);
            $existing=HeldCartOperation::where('operation_id',$key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->actor_id!==$actor->id || (int)$existing->device_id!==(int)$actor->currentAccessToken()->device_id || !hash_equals($existing->request_fingerprint,$hash)) throw new ApiException('OPERATION_ID_REUSED','This hold reference was already used with different details.',409);
                $cart=HeldCart::findOrFail($existing->held_cart_id); $this->branch($actor,Branch::findOrFail($cart->branch_id)->publicId());
                return [...$existing->result,'replayed'=>true];
            }
            [$cart,$result]=$perform($actor);
            HeldCartOperation::create(['held_cart_id'=>$cart->id,'actor_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$key,'request_fingerprint'=>$hash,'action'=>$action,'result'=>$result]);
            return [...$result,'replayed'=>false];
        },3);
    }
    private function branch(User $actor,string $id): Branch {
        $branch=(new Branch)->resolveRouteBindingQuery(Branch::query(),$id)->lockForUpdate()->first();
        if (!$branch || !$branch->is_active) throw new ApiException('NOT_FOUND','Active branch not found.',404);
        if (!$actor->hasRole('owner') && (int)$actor->branch_id!==(int)$branch->id) throw new AuthorizationException;
        return $branch;
    }
    private function draft(array $input): array {
        $lines=[];
        foreach($input['items'] as $entry) {
            $product=(new Product)->resolveRouteBinding($entry['product_id']);
            if (!$product || $product->trashed() || !$product->is_active) throw new ApiException('PRODUCT_INACTIVE','A held product is no longer available. Remove it from a new cart.',409);
            $pack=isset($entry['packaging_id'])?(new ProductPackaging)->resolveRouteBinding($entry['packaging_id']):null;
            if (isset($entry['packaging_id']) && (!$pack || (int)$pack->product_id!==(int)$product->id || !$pack->is_active || $pack->archived_at)) throw new ApiException('PACKAGING_INACTIVE','A held package is no longer available. Use its current package in a new cart.',409);
            try {
                $atoms=Quantity::parse($entry['qty'],!$pack && $product->quantity_type==='weight');
                if ($atoms<1) throw new \InvalidArgumentException('Quantity must be positive.');
                if ($pack) Quantity::packageAtoms($entry['qty'],$pack->conversion_atoms);
                $discount=Money::amount($entry['discount']??0);
            } catch(\InvalidArgumentException|\OverflowException $e) { throw new ApiException('VALIDATION_ERROR',$e->getMessage(),422); }
            $lines[]=['product_id'=>$product->publicId(),'packaging_id'=>$pack?->publicId(),'qty'=>Quantity::format($atoms,!$pack && $product->quantity_type==='weight'),'discount'=>$discount];
        }
        $customer=isset($input['customer_id'])?(new Customer)->resolveRouteBinding($input['customer_id']):null;
        if (isset($input['customer_id']) && !$customer) throw new ApiException('NOT_FOUND','Customer not found.',404);
        $discount=Money::amount($input['discount']??0); $credit=Money::amount($input['credit_amount']??0);
        if ($credit>0 && !$customer) throw new ApiException('VALIDATION_ERROR','Select a customer for credit.',422);
        $reason=trim($input['discount_reason']??'');
        if (($discount>0 || collect($lines)->contains(fn($line)=>$line['discount']>0)) && $reason==='') throw new ApiException('VALIDATION_ERROR','Enter a discount reason.',422);
        return ['items'=>$lines,'customer_id'=>$customer?->publicId(),'credit_amount'=>$credit,'discount'=>$discount,'discount_reason'=>$reason];
    }
    private function resource(HeldCart $cart): array {
        return ['id'=>$cart->publicId(),'branch_id'=>Branch::findOrFail($cart->branch_id)->publicId(),'status'=>$cart->status,
            'draft'=>$cart->draft,'created_at'=>$cart->created_at->toISOString(),'expires_at'=>$cart->expires_at->toISOString()];
    }
}
