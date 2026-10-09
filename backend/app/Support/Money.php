<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    // JSON integer amounts must also remain exact in the React browser.
    public const MAX_AMOUNT = 9_007_199_254_740_991;

    public static function amount(mixed $value): int
    {
        if (! is_int($value) || $value < 0) { throw new InvalidArgumentException('Money must be a nonnegative integer TSh amount.'); }
        if ($value > self::MAX_AMOUNT) { throw new OverflowException('Amount is too large for an exact API response.'); }
        return $value;
    }

    public static function lineTotal(mixed $quantityAtoms, mixed $unitPrice, bool $weighted = false, mixed $discount = 0): int
    {
        $price = self::amount($unitPrice); $discount = self::amount($discount);
        $gross = self::amount(ExactMath::roundProductRatio($quantityAtoms, $price, $weighted ? Quantity::GRAMS_PER_KILOGRAM : 1));
        if ($discount > $gross) { throw new InvalidArgumentException('A discount cannot make the line negative.'); }
        return $gross - $discount;
    }
}
