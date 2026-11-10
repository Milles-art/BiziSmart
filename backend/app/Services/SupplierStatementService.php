<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Supplier, SupplierInvoice, SupplierPayment, SupplierCredit, User};
use App\Support\{Money, ReportMath, ReportPeriod};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only journal statement. Applying an existing credit never creates a second deduction. */
class SupplierStatementService
{
    private const JOURNALS = [
        'invoice' => [SupplierInvoice::class, 'original_amount'],
        'payment' => [SupplierPayment::class, 'amount'],
        'credit' => [SupplierCredit::class, 'amount'],
    ];

    public function __construct(private FinancialActorService $actors) {}

    public function build(User $actor, Supplier $supplier, array $filters, bool $export = false): array
    {
        $this->actors->validate($actor, 'manage_products');
        if (! $actor->hasRole('owner')) { throw new AuthorizationException; }
        $supplier->assertCurrentBusiness();
        $today = now('Africa/Dar_es_Salaam');
        try {
            $period = new ReportPeriod($filters['from'] ?? $today->copy()->subDays(29)->toDateString(), $filters['to'] ?? $today->toDateString());
        } catch (\InvalidArgumentException $e) {
            throw new ApiException('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        return DB::transaction(function () use ($supplier, $filters, $export, $period) {
            $ledger = $this->ledger($supplier)->where('created_at', '>=', $period->start)->where('created_at', '<', $period->until);
            $total = (clone $ledger)->count();
            if ($export && $total > 5000) {
                throw new ApiException('STATEMENT_EXPORT_TOO_LARGE', 'Choose a shorter period with at most 5000 statement entries.', 422);
            }
            $perPage = $export ? 5000 : (int) ($filters['per_page'] ?? 50);
            $page = $export ? 1 : (int) ($filters['page'] ?? 1);
            $offset = ($page - 1) * $perPage;
            $opening = $this->balance($this->ledger($supplier)->where('created_at', '<', $period->start));
            $totals = [];
            foreach (array_keys(self::JOURNALS) as $kind) {
                $totals[$kind] = $this->sum((clone $ledger)->where('kind', $kind), 'amount');
            }
            $closing = ReportMath::add(ReportMath::add(ReportMath::add($opening, $totals['invoice']), -$totals['payment']), -$totals['credit']);
            $rows = (clone $ledger)->orderBy('created_at')->orderBy('kind')->orderBy('id')->offset($offset)->limit($perPage)->get();
            $running = $opening;
            if ($first = $rows->first()) {
                // Include the complete prefix, including earlier pages and same-second journal ties.
                $prefix = (clone $ledger)->where(function (Builder $q) use ($first) {
                    $q->where('created_at', '<', $first->created_at)->orWhere(function (Builder $q) use ($first) {
                        $q->where('created_at', $first->created_at)->where(function (Builder $q) use ($first) {
                            $q->where('kind', '<', $first->kind)->orWhere(function (Builder $q) use ($first) {
                                $q->where('kind', $first->kind)->where('id', '<', $first->id);
                            });
                        });
                    });
                });
                $running = ReportMath::add($opening, $this->balance($prefix));
            }
            $entries = [];
            $models = [];
            foreach (self::JOURNALS as $kind => [$model]) {
                $models[$kind] = $model::whereIn('id', $rows->where('kind', $kind)->pluck('id'))->get()->keyBy('id');
            }
            // Decrypt only the bounded result window, using three batched lookups rather than one per entry.
            foreach ($rows as $row) {
                $model = $models[$row->kind]->get($row->id);
                if (! $model) { throw new \LogicException('A statement journal disappeared during a snapshot read.'); }
                $amount = $this->exact($row->amount);
                $running = ReportMath::add($running, $row->kind === 'invoice' ? $amount : -$amount);
                $at = $model->created_at->timezone('Africa/Dar_es_Salaam');
                $entries[] = [
                    'id' => $model->publicId(), 'kind' => $row->kind,
                    'at' => $at->toISOString(), 'date' => $at->toDateString(),
                    'document_date' => $row->kind === 'invoice' ? $model->invoice_date->toDateString() : ($row->kind === 'payment' ? $model->paid_on->toDateString() : null),
                    'reference' => $row->kind === 'invoice' ? $model->invoice_reference : $model->reference,
                    'charge' => $row->kind === 'invoice' ? $amount : 0,
                    'payment' => $row->kind === 'payment' ? $amount : 0,
                    'credit' => $row->kind === 'credit' ? $amount : 0, 'balance' => $running,
                ];
            }
            return [
                'supplier' => ['id' => $supplier->publicId(), 'name' => $supplier->name, 'phone' => $supplier->phone],
                'period' => ['from' => $period->from, 'to' => $period->to, 'timezone' => 'Africa/Dar_es_Salaam', 'basis' => 'recorded_at'],
                'opening_balance' => $opening, 'charges' => $totals['invoice'], 'payments' => $totals['payment'], 'credits' => $totals['credit'], 'closing_balance' => $closing,
                'outstanding_payable' => $this->sum(SupplierInvoice::where('supplier_id', $supplier->id)->toBase(), 'remaining_amount'),
                'unapplied_credit' => $this->sum(SupplierCredit::where('supplier_id', $supplier->id)->toBase(), 'remaining_amount'),
                'currency' => 'TSh', 'entries' => $entries,
                'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
            ];
        });
    }

    private function ledger(Supplier $supplier): Builder
    {
        $union = null;
        foreach (self::JOURNALS as $kind => [$model, $column]) {
            $query = $model::where('supplier_id', $supplier->id)->select(['id', 'created_at', $column.' as amount'])->selectRaw("'".$kind."' as kind")->toBase();
            $union = $union ? $union->unionAll($query) : $query;
        }
        return DB::query()->fromSub($union, 'supplier_statement');
    }

    private function balance(Builder $query): int
    {
        $balance = 0;
        foreach (array_keys(self::JOURNALS) as $kind) {
            $amount = $this->sum((clone $query)->where('kind', $kind), 'amount');
            $balance = ReportMath::add($balance, $kind === 'invoice' ? $amount : -$amount);
        }
        return $balance;
    }

    private function sum(Builder $query, string $column): int { return $this->exact($query->sum($column)); }

    private function exact(mixed $amount): int
    {
        $text = (string) $amount;
        $max = (string) Money::MAX_AMOUNT;
        if (! preg_match('/^\d+$/D', $text) || strlen($text) > strlen($max) || (strlen($text) === strlen($max) && strcmp($text, $max) > 0)) {
            throw new ApiException('REPORT_TOTAL_TOO_LARGE', 'The statement total exceeds the exact API range.', 422);
        }
        return (int) $text;
    }
}
