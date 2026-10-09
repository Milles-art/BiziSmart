<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class ExactMath
{
    private static function integer(mixed $value): int
    {
        if (! is_int($value)) { throw new InvalidArgumentException('Exact arithmetic requires integers.'); }
        return $value;
    }

    public static function add(mixed $left, mixed $right): int
    {
        $left = self::integer($left); $right = self::integer($right);
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new OverflowException('Quantity or amount is too large.');
        }
        return $left + $right;
    }

    public static function multiply(mixed $left, mixed $right): int
    {
        $left = self::integer($left); $right = self::integer($right);
        if ($left < 0 || $right < 0) { throw new InvalidArgumentException('Multiplication requires nonnegative integers.'); }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) { throw new OverflowException('Quantity or amount is too large.'); }
        return $left * $right;
    }

    /** Round a nonnegative rational product half-up, without constructing an overflowing numerator. */
    public static function roundProductRatio(mixed $left, mixed $right, mixed $denominator): int
    {
        $left = self::integer($left); $right = self::integer($right); $denominator = self::integer($denominator);
        if ($left < 0 || $right < 0 || $denominator < 1 || $denominator > 1_000_000) {
            throw new InvalidArgumentException('Nonnegative operands and a denominator from 1 to 1000000 are required.');
        }
        $whole = self::multiply(intdiv($left, $denominator), $right);
        $remainder = $left % $denominator;
        $whole = self::add($whole, self::multiply($remainder, intdiv($right, $denominator)));
        $smallProduct = $remainder * ($right % $denominator); // Less than 10^12, safe on the required 64-bit runtime.
        $whole = self::add($whole, intdiv($smallProduct, $denominator));
        $threshold = intdiv($denominator, 2) + ($denominator % 2);
        return self::add($whole, ($smallProduct % $denominator) >= $threshold ? 1 : 0);
    }
}
