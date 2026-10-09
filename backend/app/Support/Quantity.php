<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Decimal wire quantities become whole items or integer grams; never floating-point numbers. */
final class Quantity
{
    public const GRAMS_PER_KILOGRAM = 1000;

    public static function parse(mixed $value, bool $weighted = false, bool $allowNegative = false): int
    {
        $precision = $weighted ? 3 : 0;
        $fractionPattern = $weighted ? '(?:\.[0-9]{1,3})?' : '';
        $signPattern = $allowNegative ? '-?' : '';
        if (! is_string($value) || ! preg_match('/^'.$signPattern.'(?:0|[1-9][0-9]*)'.$fractionPattern.'$/D', $value)) {
            throw new InvalidArgumentException($weighted ? 'Enter a decimal string in kilograms with at most three decimal places.' : 'Enter a whole-item quantity string.');
        }
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $digits = $whole.str_pad($fraction, $precision, '0');
        $atoms = 0;
        foreach (str_split($digits) as $character) {
            $digit = ord($character) - ord('0');
            if ($negative) {
                if ($atoms < intdiv(PHP_INT_MIN + $digit, 10)) { throw new OverflowException('Quantity is too large.'); }
                $atoms = $atoms * 10 - $digit;
            } else {
                if ($atoms > intdiv(PHP_INT_MAX - $digit, 10)) { throw new OverflowException('Quantity is too large.'); }
                $atoms = $atoms * 10 + $digit;
            }
        }
        return $atoms;
    }

    public static function format(mixed $atoms, bool $weighted = false): string
    {
        if (! is_int($atoms)) { throw new InvalidArgumentException('Stored quantities must be integer atoms.'); }
        if (! $weighted) { return (string) $atoms; }
        $digits = str_pad(ltrim((string) $atoms, '-'), 4, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -3), '0');
        return ($atoms < 0 ? '-' : '').substr($digits, 0, -3).($fraction === '' ? '' : '.'.$fraction);
    }

    public static function packageAtoms(mixed $packageCount, mixed $atomsPerPackage): int
    {
        $count = self::parse($packageCount);
        if (! is_int($atomsPerPackage) || $atomsPerPackage < 1) { throw new InvalidArgumentException('A positive integer package conversion is required.'); }
        return ExactMath::multiply($count, $atomsPerPackage);
    }
}
