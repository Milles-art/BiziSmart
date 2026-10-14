<?php

namespace App\Services;

use App\Support\ExactMath;
use App\Support\Money;
use OverflowException;

/** Calculates server-owned cash totals; callers resolve and lock the underlying records. */
final class TillCashService
{
    public function expected(mixed $openingFloat, mixed $cashSales = 0, mixed $cashDebtPayments = 0,
        mixed $cashRefunds = 0, mixed $paidOuts = 0, mixed $cashDrops = 0): int
    {
        $expected = 0;
        foreach ([$openingFloat, $cashSales, $cashDebtPayments] as $amount) {
            $expected = ExactMath::add($expected, Money::amount($amount));
        }
        foreach ([$cashRefunds, $paidOuts, $cashDrops] as $amount) {
            $expected = ExactMath::add($expected, -Money::amount($amount));
        }
        return $this->signedAmount($expected);
    }

    public function reconciliation(int $expectedCash, mixed $countedCash, mixed $varianceLimit): array
    {
        $expected = $this->signedAmount($expectedCash);
        $counted = Money::amount($countedCash);
        $limit = Money::amount($varianceLimit);
        $variance = $this->signedAmount(ExactMath::add($counted, -$expected));
        return ['expected_cash' => $expected, 'counted_cash' => $counted, 'variance' => $variance,
            'approval_required' => $variance > $limit || $variance < -$limit];
    }

    private function signedAmount(int $amount): int
    {
        if ($amount > Money::MAX_AMOUNT || $amount < -Money::MAX_AMOUNT) {
            throw new OverflowException('Amount is too large for an exact API response.');
        }
        return $amount;
    }
}
