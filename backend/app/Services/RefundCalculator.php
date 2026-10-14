<?php

namespace App\Services;

use App\Support\ExactMath;
use App\Support\Money;
use InvalidArgumentException;

/** Original discounted line value, rounded cumulatively to avoid repeated-refund drift. */
final class RefundCalculator
{
    /** Cash/credit receipts: cash includes earlier collections, less earlier paid refunds. */
    public function cashSettlement(int $refund, int $unpaidInvoice, int $availablePaidCash): array
    {
        Money::amount($refund);
        Money::amount($unpaidInvoice);
        Money::amount($availablePaidCash);
        $debt = min($refund, $unpaidInvoice);
        $cash = $refund - $debt;
        if ($cash > $availablePaidCash) {
            throw new InvalidArgumentException('The refund exceeds the remaining payment on this receipt.');
        }

        return ['debt_reduction' => $debt, 'cash_refund' => $cash];
    }

    /** Allocate the paid portion proportionally over the remaining refundable pools. */
    public function splitSettlement(int $refund,int $unpaid,int $cash,int $mobile):array {
        foreach([$refund,$unpaid,$cash,$mobile] as $n) Money::amount($n);
        $debt=min($refund,$unpaid);$paid=$refund-$debt;$pool=Money::amount(ExactMath::add($cash,$mobile));
        if($paid>$pool) throw new InvalidArgumentException('The refund exceeds the remaining payment on this receipt.');
        $cashRefund=$pool===0?0:$this->entitlement($paid,$cash,$pool);
        return ['debt_reduction'=>$debt,'cash_refund'=>$cashRefund,'mobile_refund'=>$paid-$cashRefund];
    }

    public function line(int $originalAmount, int $soldAtoms, int $returnedBefore, int $returnAtoms): int
    {
        Money::amount($originalAmount);
        if ($soldAtoms < 1 || $returnedBefore < 0 || $returnedBefore > $soldAtoms
            || $returnAtoms < 1 || $returnAtoms > $soldAtoms - $returnedBefore) {
            throw new InvalidArgumentException('The returned quantity exceeds the original sale quantity.');
        }

        return $this->entitlement($originalAmount, $returnedBefore + $returnAtoms, $soldAtoms)
            - $this->entitlement($originalAmount, $returnedBefore, $soldAtoms);
    }

    /** Half-up amount * numerator / denominator, with no floats or overflowing product. */
    private function entitlement(int $amount, int $numerator, int $denominator): int
    {
        $whole = 0;
        $remainder = 0;
        foreach (str_split(decbin($amount)) as $bit) {
            $whole = ExactMath::multiply($whole, 2);
            // Compare before adding: even a denominator near PHP_INT_MAX is safe.
            if ($remainder >= $denominator - $remainder) {
                $remainder -= $denominator - $remainder;
                $whole++;
            } else {
                $remainder += $remainder;
            }
            if ($bit === '1') {
                if ($remainder >= $denominator - $numerator) {
                    $remainder -= $denominator - $numerator;
                    $whole++;
                } else {
                    $remainder += $numerator;
                }
            }
        }

        $half = intdiv($denominator, 2) + $denominator % 2;
        return Money::amount($whole + ($remainder >= $half ? 1 : 0));
    }
}
