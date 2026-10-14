<?php
namespace App\Support;
use InvalidArgumentException;
use OverflowException;

final class ReportMath {
    public static function add(int $left,int $right): int {
        $sum=ExactMath::add($left,$right);
        if ($sum < -Money::MAX_AMOUNT || $sum > Money::MAX_AMOUNT) throw new OverflowException('The report total exceeds the exact API range.');
        return $sum;
    }
    /** Cumulative shares make partial-return reversals sum to the original VAT or cost exactly. */
    public static function reversal(int $original,int $basis,int $already,int $added): int {
        if ($already<0 || $added<0 || $basis<0 || $already>$basis || $added>$basis-$already) throw new InvalidArgumentException('A reversal exceeds its original basis.');
        if ($basis===0) {
            if ($original!==0) throw new InvalidArgumentException('A nonzero amount needs a positive basis.');
            return 0;
        }
        return ExactMath::roundProportion(Money::amount($original),$already+$added,$basis)-ExactMath::roundProportion($original,$already,$basis);
    }
}
