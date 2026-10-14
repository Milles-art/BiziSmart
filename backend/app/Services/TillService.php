<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business, Branch, Device, PersonalAccessToken, Plan, SupervisorApproval, TillSession, TillCashEntry, TillOperation, User};
use App\Support\{Money, ExactMath, Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\{AuthenticationException, Access\AuthorizationException};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class TillService {
    public function __construct(private TenantContext $context, private TillAccessService $access, private TillCashService $cash) {}

    public function current(User $actor): array {
        $actor->assertCurrentBusiness();
        $branch = $actor->branch;
        $till = TillSession::where('cashier_id',$actor->id)->where('branch_id',$actor->branch_id)->where('status','open')->first();
        return ['till'=>$till ? $this->view($till) : null, 'branch'=>$branch ? ['id'=>$branch->publicId(),'name'=>$branch->name] : null,
            'variance_limit'=>$this->limit(), 'can_open'=>$branch?->is_active ?? false];
    }

    public function overview(User $actor, array $filters): Builder {
        $actor->assertCurrentBusiness();
        $query = TillSession::with(['branch','cashier','approver']);
        if ($actor->hasRole('manager')) { $query->where('branch_id',$actor->branch_id ?? 0); }
        elseif (! $actor->hasRole('owner')) { $query->where('cashier_id',$actor->id); }
        if (isset($filters['status'])) { $query->where('status',$filters['status']); }
        return $query->orderByDesc('opened_at')->orderByDesc('id');
    }

    public function ownerReviewCount(User $actor): int {
        $actor->assertCurrentBusiness();
        return $actor->hasRole('owner') ? TillSession::where('owner_review_required',true)->count() : 0;
    }

    public function open(User $original, array $input): array {
        return DB::transaction(function () use ($original,$input) {
            $actor=$this->actor($original); $shop=$this->shop();
            $snapshot=['operation_id'=>strtolower($input['operation_id']),'branch_id'=>$input['branch_id'],'opening_float'=>Money::amount($input['opening_float'])];
            if ($replay=$this->replay($actor,'open',$snapshot)) { return $replay; }
            $branch=(new Branch)->resolveRouteBinding($input['branch_id']);
            if (! $branch) { throw new ApiException('NOT_FOUND','The requested branch was not found.',404); }
            if (! $branch->is_active || ! $this->access->canManage($actor,$branch,$actor)) { throw new AuthorizationException; }
            if (TillSession::where('cashier_id',$actor->id)->where('branch_id',$branch->id)->where('status','open')->exists()) { throw new ApiException('TILL_ALREADY_OPEN','Close your existing till before opening another.',409); }
            $plan=Plan::whereKey($shop->plan_id)->lockForUpdate()->firstOrFail(); $limits=$plan->limits ?? [];
            if (! array_key_exists('tills',$limits) || ($limits['tills'] !== null && (! is_int($limits['tills']) || $limits['tills'] < 0 || TillSession::where('status','open')->count() >= $limits['tills']))) { throw new ApiException('PLAN_LIMIT_REACHED','Your plan’s open till limit has been reached.',403); }
            $till=TillSession::create(['branch_id'=>$branch->id,'cashier_id'=>$actor->id,'opening_float'=>$snapshot['opening_float'],'status'=>'open','opened_at'=>now(),'revision'=>0]);
            $this->audit($actor,$till,'till_opened',['opening_float'=>$till->opening_float]);
            return $this->remember($actor,'open',$snapshot,['till'=>$this->view($till)]);
        },3);
    }

    public function paidOut(User $original,array $input): array {
        return DB::transaction(function () use ($original,$input) {
            $actor=$this->actor($original); $this->shop();
            $snapshot=['operation_id'=>strtolower($input['operation_id']),'till_id'=>$input['till_id'],'amount'=>Money::amount($input['amount']),'reason'=>trim($input['reason'])];
            if ($snapshot['amount'] === 0 || $snapshot['reason'] === '') { throw new ApiException('VALIDATION_ERROR','Enter a positive amount and reason.',422); }
            if ($replay=$this->replay($actor,'paid_out',$snapshot)) { return $replay; }
            $till=$this->ownOpen($actor,$input['till_id']);
            $entry=$this->recordCash($actor,$till,'paid_out',$snapshot['amount'],$snapshot['operation_id'],$snapshot['reason']);
            $this->audit($actor,$till,'till_paid_out',['entry_id'=>$entry->publicId(),'amount'=>$entry->amount]);
            return $this->remember($actor,'paid_out',$snapshot,['till'=>$this->view($till),'entry_id'=>$entry->publicId()]);
        },3);
    }

    /** Internal owner-only correction of a recorded handover after cash has physically returned. */
    public function recordDropReversal(User $actor,TillSession $till,int $amount,string $operation,string $reason):TillCashEntry {
        $actor->assertCurrentBusiness();$till->assertCurrentBusiness();
        if(DB::transactionLevel()<1||!$actor->hasRole('owner')||!$actor->can('manage_till'))throw new AuthorizationException;
        $till->refreshForUpdate();if($till->status!=='open')throw new ApiException('TILL_CLOSED','This till is closed.',409);
        $entry=TillCashEntry::create(['till_session_id'=>$till->id,'user_id'=>$actor->id,'type'=>'cash_drop_reversal','amount'=>Money::amount($amount),'reason'=>$reason,'operation_id'=>strtolower($operation),'request_fingerprint'=>SupervisorApprovalService::snapshotHash(['till_id'=>$till->publicId(),'amount'=>$amount,'reason'=>$reason])]);
        $till->revision=Money::amount(ExactMath::add($till->revision,1));$till->save();$this->expected($till);return $entry;
    }

    /** Internal domain hook only. Sales/refunds/debt/drop services must call inside their outer transaction. */
    public function recordCash(User $actor,TillSession $till,string $type,mixed $amount,string $operation,?string $reason=null): TillCashEntry {
        $actor->assertCurrentBusiness(); $till->assertCurrentBusiness();
        if (DB::transactionLevel() < 1) { throw new \LogicException('Cash entries require the domain transaction.'); }
        $amount=Money::amount($amount);
        if (! in_array($type,['cash_sale','cash_debt_payment','cash_refund','paid_out','cash_drop','expense_refund'],true) || Money::amount($amount) === 0) { throw new \InvalidArgumentException('A positive supported cash entry is required.'); }
        $till->refreshForUpdate();
        if (! $this->access->canManage($actor,$till->branch,$till->cashier)) { throw new AuthorizationException; }
        if ($till->status !== 'open') { throw new ApiException('TILL_CLOSED','This till is closed.',409); }
        $fingerprint=SupervisorApprovalService::snapshotHash(['till_id'=>$till->publicId(),'type'=>$type,'amount'=>$amount,'reason'=>$reason]);
        $existing=TillCashEntry::where('operation_id',strtolower($operation))->first();
        if ($existing) {
            if (! hash_equals($existing->request_fingerprint,$fingerprint) || (int)$existing->user_id !== (int)$actor->id) { throw new ApiException('OPERATION_ID_REUSED','This cash reference is already in use.',409); }
            return $existing;
        }
        $entry=TillCashEntry::create(['till_session_id'=>$till->id,'user_id'=>$actor->id,'type'=>$type,'amount'=>$amount,'reason'=>$reason,'operation_id'=>strtolower($operation),'request_fingerprint'=>$fingerprint]);
        $till->revision=Money::amount(ExactMath::add($till->revision,1)); $till->save();
        $this->expected($till); // Overflow aborts the entire caller transaction.
        return $entry;
    }

    public function preview(User $original,array $input): array {
        return DB::transaction(function () use ($original,$input) {
            $actor=$this->actor($original); $this->shop(); $till=$this->ownOpen($actor,$input['till_id']);
            $review=$this->review($till,$input['counted_cash']);
            $snapshot=$this->snapshot($till,$input['operation_id'],$review['counted_cash'],$review['expected_cash'],$review['variance_limit'],$till->revision);
            return [...$review,'snapshot'=>$snapshot,'payload_hash'=>SupervisorApprovalService::snapshotHash($snapshot)];
        },3);
    }

    public function close(User $original,#[\SensitiveParameter] array $input): array {
        return DB::transaction(function () use ($original,$input) {
            $actor=$this->actor($original,$input['approval_token'] ?? null); $this->shop();
            $request=['operation_id'=>strtolower($input['operation_id']),'till_id'=>$input['till_id'],'counted_cash'=>Money::amount($input['counted_cash']),
                'expected_cash'=>$input['expected_cash'],'revision'=>$input['revision'],'variance_limit'=>$input['variance_limit']];
            if ($replay=$this->replay($actor,'close',$request)) { return $replay; }
            $till=$this->ownOpen($actor,$input['till_id']); $review=$this->review($till,$input['counted_cash']);
            if ($review['expected_cash'] !== $input['expected_cash'] || $till->revision !== $input['revision'] || $review['variance_limit'] !== $input['variance_limit']) { throw new ApiException('TILL_CHANGED','Till cash or settings changed. Review the closing count again.',409); }
            $snapshot=$this->snapshot($till,$input['operation_id'],$review['counted_cash'],$review['expected_cash'],$review['variance_limit'],$till->revision);
            $write=function (?User $approver=null) use ($actor,$till,$review,$request) {
                $till->status='closed'; $till->closing_cash=$review['counted_cash']; $till->expected_cash=$review['expected_cash'];
                $till->variance=$review['variance']; $till->variance_limit=$review['variance_limit']; $till->closed_at=now(); $till->approved_by=$approver?->id;
                $till->owner_review_required=$review['approval_required']; $till->save();
                $this->audit($actor,$till,'till_closed',['expected_cash'=>$till->expected_cash,'closing_cash'=>$till->closing_cash,'variance'=>$till->variance,'approved_by'=>$approver?->publicId()]);
                return $this->remember($actor,'close',$request,['till'=>$this->view($till)]);
            };
            if (! $review['approval_required']) { return $write(); }
            if (empty($input['approval_token'])) { throw new ApiException('SUPERVISOR_REQUIRED','An owner or your branch manager must approve this variance.',403); }
            return app(SupervisorApprovalService::class)->execute($actor,$input['approval_token'],'approve_variance',$input['operation_id'],$snapshot,$write);
        },3);
    }

    public function view(TillSession $till): array {
        $till->loadMissing(['branch','cashier','approver']);
        return ['id'=>$till->publicId(),'branch_id'=>$till->branch->publicId(),'branch_name'=>$till->branch->name,
            'cashier_id'=>$till->cashier->publicId(),'cashier_name'=>$till->cashier->name,'status'=>$till->status,
            'opening_float'=>$till->opening_float,'expected_cash'=>$till->status === 'closed' ? $till->expected_cash : $this->expected($till),
            'closing_cash'=>$till->closing_cash,'variance'=>$till->variance,'revision'=>$till->revision,
            'opened_at'=>$till->opened_at->toISOString(),'closed_at'=>$till->closed_at?->toISOString(),
            'approved_by'=>$till->approver?->publicId(),'owner_review_required'=>$till->owner_review_required];
    }

    private function expected(TillSession $till): int {
        $totals=array_fill_keys(['cash_sale','cash_debt_payment','cash_refund','paid_out','cash_drop','expense_refund','cash_drop_reversal'],0);
        $query=TillCashEntry::where('till_session_id',$till->id)->whereIn('type',array_keys($totals));
        // Current reads are required after waiting for the drawer lock in MySQL repeatable read.
        if (DB::transactionLevel()>0) $query->lockForUpdate();
        try {
            $query->select(['id','type','amount'])->chunkById(200,function($entries)use(&$totals){
                foreach($entries as $entry) $totals[$entry->type]=Money::amount(ExactMath::add($totals[$entry->type],Money::amount($entry->amount)));
            });
            $incoming=Money::amount(ExactMath::add($totals['cash_sale'],ExactMath::add($totals['expense_refund'],$totals['cash_drop_reversal'])));
            return $this->cash->expected($till->opening_float,$incoming,$totals['cash_debt_payment'],$totals['cash_refund'],$totals['paid_out'],$totals['cash_drop']);
        } catch (\InvalidArgumentException|\OverflowException $e) {
            throw new ApiException('VALIDATION_ERROR','Till cash exceeds the supported amount.',422);
        }
    }
    private function review(TillSession $till,mixed $counted): array {
        if(\App\Models\CashDrop::where('till_session_id',$till->id)->where('status','pending')->lockForUpdate()->first(['id']))throw new ApiException('PENDING_CASH_DROP','Acknowledge or formally reverse pending cash handovers before closing this till.',409);
        try { return [...$this->cash->reconciliation($this->expected($till),$counted,$this->limit()),'variance_limit'=>$this->limit()]; }
        catch (\OverflowException $e) { throw new ApiException('VALIDATION_ERROR','The closing variance exceeds the supported amount.',422); }
    }
    private function snapshot(TillSession $till,string $operation,int $counted,int $expected,int $limit,int $revision): array {
        return ['operation_id'=>strtolower($operation),'till_id'=>$till->publicId(),'branch_id'=>$till->branch->publicId(),
            'counted_cash'=>$counted,'expected_cash'=>$expected,'variance_limit'=>$limit,'revision'=>$revision];
    }
    private function limit(): int { return Money::amount(Business::findOrFail($this->context->requireId())->settings['till_variance_limit'] ?? 5000); }
    private function shop(): Business { return Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail(); }
    private function ownOpen(User $actor,string $id): TillSession {
        $till=(new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch','cashier']),$id)->lockForUpdate()->firstOrFail();
        if (! $this->access->canManage($actor,$till->branch,$till->cashier)) { throw new AuthorizationException; }
        if ($till->status !== 'open') { throw new ApiException('TILL_CLOSED','This till is already closed.',409); }
        return $till;
    }
    private function actor(User $original,#[\SensitiveParameter] ?string $plain=null): User {
        $original->assertCurrentBusiness();
        $grant=$plain ? SupervisorApproval::where('token_hash',hash('sha256',$plain))->first() : null;
        $actor=User::whereIn('id',array_unique(array_filter([$original->id,$grant?->approver_id])))->orderBy('id')->lockForUpdate()->get()->keyBy('id')->get($original->id);
        $token=$original->currentAccessToken();
        $current=$token ? PersonalAccessToken::whereKey($token->id)->where('tokenable_id',$original->id)->lockForUpdate()->first() : null;
        $device=$current?->device_id ? Device::whereKey($current->device_id)->lockForUpdate()->first() : null;
        if (! $actor || ! $actor->is_active || $actor->must_change_pin || $actor->getRoleNames()->count() !== 1 || ($actor->hasRole('owner') && ! $actor->totp_confirmed_at)
            || ! $current || $current->expires_at?->isPast() || $device?->status !== 'approved') { throw new AuthenticationException; }
        if (! $actor->can('manage_till')) { throw new AuthorizationException; }
        return $actor->withAccessToken($current);
    }
    private function replay(User $actor,string $type,array $snapshot): ?array {
        $old=TillOperation::where('operation_id',$snapshot['operation_id'])->first();
        if (! $old) { return null; }
        if ($old->type !== $type || (int)$old->user_id !== (int)$actor->id || (int)$old->device_id !== (int)$actor->currentAccessToken()->device_id
            || ! hash_equals($old->request_fingerprint,SupervisorApprovalService::snapshotHash($snapshot))) { throw new ApiException('OPERATION_ID_REUSED','This till reference was used with different details.',409); }
        return [...$old->result,'replayed'=>true];
    }
    private function remember(User $actor,string $type,array $snapshot,#[\SensitiveParameter] array $result): array {
        TillOperation::create(['user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$snapshot['operation_id'],'type'=>$type,
            'request_fingerprint'=>SupervisorApprovalService::snapshotHash($snapshot),'result'=>$result]);
        return [...$result,'replayed'=>false];
    }
    private function audit(User $actor,TillSession $till,string $event,array $properties): void {
        activity('tills')->performedOn($till)->causedBy($actor)->event($event)->withProperties($properties)->log('Till cash changed');
    }
}
