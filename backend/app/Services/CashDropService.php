<?php

namespace App\Services;

use App\Exceptions\ApiException;

use App\Models\{Branch, CashDrop, TillSession, User};

use App\Support\Money;

use Illuminate\Auth\Access\AuthorizationException;

use Illuminate\Support\Facades\DB;

class CashDropService
{
    public function __construct(private FinancialJournalService $journal)
    {
    }

    public function overview(User $a)
    {
        $a->assertCurrentBusiness();
        $q = CashDrop::query();
        if ($a->hasRole('manager')) $q->where('branch_id', $a->branch_id ?? 0);
        elseif (! $a->hasRole('owner')) $q->where('cashier_id', $a->id);
        return $q->orderByDesc('id');
    }

    public function view(CashDrop $d): array
    {
        $d->assertCurrentBusiness();
        return ['id' => $d->publicId(), 'till_id' => TillSession::findOrFail($d->till_session_id)->publicId(
            ), 'amount' => $d->amount, 'reason' => $d->reason, 'status' => $d->status, 'cashier' => User
                ::withTrashed()->findOrFail($d->cashier_id)->name, 'receiver_id' => User::withTrashed(
            )->findOrFail($d->receiver_id)->publicId(), 'receiver' => User::withTrashed()->findOrFail(
                $d->receiver_id
            )->name, 'acknowledged_at' => $d->acknowledged_at?->toISOString(), 'reversed_at' => $d->reversed_at
                ?->toISOString(), 'created_at' => $d->created_at->toISOString()];
    }

    public function preview(User $original, array $input): array
    {
        return DB::transaction(function () use ($original, $input) {
            $a = $this->journal->lock($original, 'manage_till');
            return $this->review($a, $input);
        }, 3);
    }

    public function create(User $original, array $input): array
    {
        return DB::transaction(function () use ($original, $input) {
            $a = $this->journal->lock($original, 'manage_till');
            $body = array_diff_key($input, ['review_hash' => 1]);
            $body['operation_id'] = strtolower($body['operation_id']);
            $target = (new TillSession)->resolveRouteBindingQuery(TillSession::with(['branch', 'cashier']),
                $body['till_id']
            )->lockForUpdate()->firstOrFail();
            if (! app(TillAccessService::class)->canManage($a, $target->branch, $target->cashier))
                throw new AuthorizationException;
            if ($old = $this->journal->replay($a, 'cash_drop', $body)) return $old;
            $r = $this->review($a, $body);
            if (! hash_equals($input['review_hash'], $r['review_hash'])) throw new ApiException(
                'TILL_CHANGED', 'The drawer cash changed. Review this handover again.', 409
            );
            $s = $r['snapshot'];
            $till = (new TillSession)->resolveRouteBinding($s['till_id']);
            $receiver = (new User)->resolveRouteBinding($s['receiver_id']);
            app(TillService::class)->recordCash($a, $till, 'cash_drop', $s['amount'], $s['operation_id'],
                $s['reason']
            );
            $d = CashDrop::create(['till_session_id' => $till->id, 'branch_id' => $till->branch_id, 'cashier_id'
                        => $a->id, 'receiver_id' => $receiver->id, 'amount' => $s['amount'], 'reason' => $s['reason'],
                    'status' => 'pending'
                ]);
            return $this->journal->remember($a, 'cash_drop', $body, ['drop' => $this->view(
                        $d
                    )]);
        }, 3);
    }

    public function acknowledge(User $original, CashDrop $drop, array $input): array
    {
        return DB::transaction(function () use ($original, $drop, $input) {
            $a = $this->journal->lock($original, 'manage_till');
            $drop->refreshForUpdate();
            $s = ['operation_id' => strtolower($input['operation_id']), 'drop_id' => $drop->publicId(
                )];
            if (! $a->hasRole('owner') && (! $a->hasRole('manager') || $a->id !== $drop->receiver_id || $a->branch_id
                        !== $drop->branch_id)) throw new AuthorizationException;
            if ($old = $this->journal->replay($a, 'drop_ack', $s)) return $old;
            if ($drop->status !== 'pending') throw new ApiException('DROP_RESOLVED',
                'This cash handover is already resolved.', 409
            );
            $drop->status = 'acknowledged';
            $drop->acknowledged_by = $a->id;
            $drop->acknowledged_at = now();
            $drop->save();
            return $this->journal->remember($a, 'drop_ack', $s, ['drop' => $this->view($drop)]);
        }, 3);
    }

    public function reverse(User $original, CashDrop $drop, array $input): array
    {
        return DB::transaction(function () use ($original, $drop, $input) {
            $a = $this->journal->lock($original);
            if (! $a->hasRole('owner')) throw new AuthorizationException;
            $drop->refreshForUpdate();
            $s = ['operation_id' => strtolower($input['operation_id']), 'drop_id' => $drop->publicId(
                ), 'reason' => trim($input['reason']), 'cash_returned' => $input['cash_returned']];
            if ($old = $this->journal->replay($a, 'drop_reverse', $s)) return $old;
            if ($drop->status === 'reversed') throw new ApiException('DROP_RESOLVED',
                'This cash drop was already reversed.', 409
            );
            $till = TillSession::whereKey($drop->till_session_id)->lockForUpdate()->firstOrFail(
            );
            if ($till->status !== 'open') throw new ApiException('TILL_CLOSED',
                'Closed drawers are immutable; resolve this handover separately.', 409
            );
            app(TillService::class)->recordDropReversal($a, $till, $drop->amount, $s['operation_id'],
                $s['reason']
            );
            $drop->status = 'reversed';
            $drop->reversed_by = $a->id;
            $drop->reversed_at = now();
            $drop->reversal_reason = $s['reason'];
            $drop->save();
            return $this->journal->remember($a, 'drop_reverse', $s, ['drop' => $this->view(
                        $drop
                    )]);
        }, 3);
    }

    private function review(User $a, array $input): array
    {
        $t = (new TillSession)->resolveRouteBindingQuery(TillSession::with(['cashier', 'branch']), $input['till_id'])
            ->lockForUpdate()->firstOrFail();
        if (! app(TillAccessService::class)->canManage($a, $t->branch, $t->cashier) || $t->status !== 'open')
            throw new AuthorizationException;
        $receiver = (new User)->resolveRouteBindingQuery(User::query(), $input['receiver_id'])->firstOrFail(
        );
        if (! $this->receiverEligible($receiver, $t->branch)) throw new AuthorizationException;
        $amount = Money::amount($input['amount']);
        $cash = app(TillService::class)->view($t)['expected_cash'];
        if ($amount < 1 || $amount > $cash) throw new ApiException('INSUFFICIENT_CASH',
            'The handover exceeds the cash in this drawer.', 409
        );
        $s = ['operation_id' => strtolower($input['operation_id']), 'till_id' => $t->publicId(
            ), 'receiver_id' => $receiver->publicId(), 'amount' => $amount, 'reason' => trim(
                $input['reason']
            ), 'revision' => $t->revision, 'expected_cash' => $cash];
        return ['snapshot' => $s, 'review_hash' => SupervisorApprovalService::snapshotHash(
                $s
            )];
    }

    /** Single receiver rule for the review path and the receiver picker: active, single-role
     staff; owners must be authentication-ready, managers must belong to the drop's branch. */
    public function receiverEligible(User $receiver, ?Branch $branch): bool
    {
        return $receiver->is_active && ! $receiver->must_change_pin && $receiver->getRoleNames(
        )->count() === 1 && (($receiver->hasRole('owner') && $receiver->ownerAuthenticationReady(
                )) || ($receiver->hasRole('manager') && $branch !== null && (int)$receiver->branch_id === (int)$branch
                    ->id));
    }
}