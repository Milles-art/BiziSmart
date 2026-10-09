<?php

use App\Support\ExactMath;
use App\Support\Money;
use App\Support\Quantity;

it('converts the approved weight examples to exact grams and back', function (string $wire, int $grams, string $canonical) {
    expect(Quantity::parse($wire, weighted: true))->toBe($grams);
    expect(Quantity::format($grams, weighted: true))->toBe($canonical);
})->with([['0.750', 750, '0.75'], ['0.001', 1, '0.001'], ['1.050', 1050, '1.05'], ['12', 12000, '12'], ['0', 0, '0']]);

it('rejects floating-point, exponent, malformed or finer-than-gram inputs', function ($wire) {
    expect(fn () => Quantity::parse($wire, weighted: true))->toThrow(InvalidArgumentException::class);
})->with([0.75, 1, '1e3', '0.0001', '1.0000', '01.5', '.5', '1.', ' 1', '+1', '-1', 'NaN']);

it('keeps counted goods whole and cartons on the same base balance', function () {
    expect(Quantity::packageAtoms('10', 24))->toBe(240);
    $stock = ExactMath::add(240, -Quantity::packageAtoms('1', 24));
    expect(ExactMath::add($stock, -Quantity::parse('2')))->toBe(214);
    expect(fn () => Quantity::parse('1.5'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Quantity::packageAtoms('1.5', 24))->toThrow(InvalidArgumentException::class);
    expect(fn () => Quantity::packageAtoms('1', 0))->toThrow(InvalidArgumentException::class);
});

it('supports signed adjustments and offline atom balances without losing int64 boundaries', function () {
    expect(Quantity::parse('-0.003', weighted: true, allowNegative: true))->toBe(-3);
    expect(Quantity::parse('-9223372036854775808', allowNegative: true))->toBe(PHP_INT_MIN);
    expect(Quantity::format(PHP_INT_MIN, weighted: true))->toBe('-9223372036854775.808');
    expect(Quantity::parse('9223372036854775.807', weighted: true))->toBe(PHP_INT_MAX);
    expect(Quantity::parse('-0.000', weighted: true, allowNegative: true))->toBe(0);
    expect(fn () => Quantity::parse('9223372036854775.808', weighted: true))->toThrow(OverflowException::class);
    expect(fn () => Quantity::parse('-9223372036854775.809', weighted: true, allowNegative: true))->toThrow(OverflowException::class);
});

it('prices weights in whole TSh with per-line half-up rounding and integer discounts', function () {
    expect(Money::lineTotal(750, 4000, weighted: true))->toBe(3000);
    expect(Money::lineTotal(750, 4000, weighted: true, discount: 100))->toBe(2900);
    expect(Money::lineTotal(1, 500, weighted: true))->toBe(1);
    expect(Money::lineTotal(1, 499, weighted: true))->toBe(0);
    expect(Money::lineTotal(24, 1200))->toBe(28800);
    expect(fn () => Money::lineTotal(1, 100, discount: 101))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::lineTotal(1.5, 100))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::amount('1200'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::amount(1.2))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::amount(Money::MAX_AMOUNT + 1))->toThrow(OverflowException::class);
    expect(Money::lineTotal(1000, Money::MAX_AMOUNT, weighted: true))->toBe(Money::MAX_AMOUNT);
    expect(fn () => Money::lineTotal(2000, Money::MAX_AMOUNT, weighted: true))->toThrow(OverflowException::class);
});

it('does not change the rounded line amount when FEFO allocates across different batches', function () {
    // One 2 g line at TSh 250/kg costs 1; rounding two allocations separately would incorrectly cost 0.
    expect(Money::lineTotal(2, 250, weighted: true))->toBe(1);
    expect(Money::lineTotal(1, 250, weighted: true) * 2)->toBe(0);
});

it('rounds large rational products exactly even when the intermediate numerator exceeds int64', function () {
    // Golden values independently computed using JavaScript BigInt.
    expect(ExactMath::roundProductRatio(PHP_INT_MAX, 999, 1000))->toBe(9214148664817921031);
    expect(ExactMath::roundProductRatio(PHP_INT_MAX, 1, 1000))->toBe(9223372036854776);
    expect(ExactMath::roundProductRatio(PHP_INT_MAX, 1000, 1000))->toBe(PHP_INT_MAX);
    expect(ExactMath::roundProductRatio(11800, 18, 118))->toBe(1800);
    expect(fn () => ExactMath::roundProductRatio(PHP_INT_MAX, 1001, 1000))->toThrow(OverflowException::class);
});

it('detects integer overflow before native PHP promotes arithmetic to a float', function () {
    expect(ExactMath::add(PHP_INT_MIN, PHP_INT_MAX))->toBe(-1);
    expect(fn () => ExactMath::add(PHP_INT_MAX, 1))->toThrow(OverflowException::class);
    expect(fn () => ExactMath::add(PHP_INT_MIN, -1))->toThrow(OverflowException::class);
    expect(fn () => ExactMath::multiply(PHP_INT_MAX, 2))->toThrow(OverflowException::class);
    expect(fn () => Quantity::packageAtoms('9223372036854775807', 24))->toThrow(OverflowException::class);
    expect(fn () => ExactMath::roundProductRatio(1, 1, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => ExactMath::roundProductRatio(1, 1, 1000001))->toThrow(InvalidArgumentException::class);
});
