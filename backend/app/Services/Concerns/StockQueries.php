<?php

namespace App\Services\Concerns;

use App\Exceptions\ApiException;
use App\Models\ProductBatch;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared batch-balance predicates for every stock service: eligibility for sale,
 * overflow-safe reading of stored balances, and sellability. Keeping these in one
 * concern prevents receiving/adjustment/allocation from drifting on expiry rules.
 */
trait StockQueries
{
    public function eligibleBatches(Builder $query, ?string $today = null): Builder
    {
        $today ??= now('Africa/Dar_es_Salaam')->toDateString();
        return $query->where('qty_remaining_atoms', '>', 0)->where('is_quarantined', false)->where('is_recalled', false)
            ->where(fn (Builder $dates) => $dates->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today));
    }

    public function checkedAtoms(mixed $value): int
    {
        try { return Quantity::parse((string) ($value ?? 0), allowNegative: true); }
        catch (\InvalidArgumentException|\OverflowException $e) { throw new ApiException('STOCK_QUANTITY_OVERFLOW', 'Recorded stock exceeds the supported quantity range. Contact the shop owner.', 409); }
    }

    public function isSellable(ProductBatch $batch): bool
    {
        $batch->assertCurrentBusiness();
        return $batch->qty_remaining_atoms > 0 && ! $batch->is_quarantined && ! $batch->is_recalled
            && ($batch->expiry_date === null || $batch->expiry_date->toDateString() >= now('Africa/Dar_es_Salaam')->toDateString());
    }
}
