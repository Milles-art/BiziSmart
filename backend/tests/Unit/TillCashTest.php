<?php

use App\Services\TillCashService;
use App\Support\Money;

it('reconciles the documented till example with exact integer shillings', function () {
    $cash = new TillCashService;
    $expected = $cash->expected(20000, 150000, cashRefunds: 5000);
    expect($cash->reconciliation($expected, 164000, 5000))->toBe([
        'expected_cash' => 165000, 'counted_cash' => 164000, 'variance' => -1000, 'approval_required' => false,
    ]);
    expect($cash->reconciliation($expected, 150000, 5000)['approval_required'])->toBeTrue();
});

it('accounts separately for cash debt receipts refunds paid outs and drops', function () {
    $cash = new TillCashService;
    expect($cash->expected(20000, 150000, cashDrops: 100000))->toBe(70000);
    expect($cash->expected(20000, 150000, 3000, 5000, 2000, 100000))->toBe(66000);
    expect($cash->expected(0))->toBe(0);
});

it('requires approval only beyond either signed variance boundary', function (int $counted, bool $required) {
    expect((new TillCashService)->reconciliation(20000, $counted, 5000)['approval_required'])->toBe($required);
})->with([[15000, false], [25000, false], [14999, true], [25001, true], [20000, false]]);

it('rejects noninteger negative or out of range cash inputs', function ($invalid) {
    $cash = new TillCashService;
    $exception = is_int($invalid) && $invalid > Money::MAX_AMOUNT ? OverflowException::class : InvalidArgumentException::class;
    foreach (range(0, 5) as $position) {
        $amounts = [0, 0, 0, 0, 0, 0]; $amounts[$position] = $invalid;
        expect(fn () => $cash->expected(...$amounts))->toThrow($exception);
    }
    expect(fn () => $cash->reconciliation(0, $invalid, 5000))->toThrow($exception);
    expect(fn () => $cash->reconciliation(0, 0, $invalid))->toThrow($exception);
})->with([-1, '1000', 1.5, true, null, Money::MAX_AMOUNT + 1]);

it('checks browser bounds for expected cash and signed variance without floats', function () {
    $cash = new TillCashService;
    expect($cash->expected(Money::MAX_AMOUNT))->toBe(Money::MAX_AMOUNT);
    expect($cash->expected(0, cashRefunds: Money::MAX_AMOUNT))->toBe(-Money::MAX_AMOUNT);
    expect(fn () => $cash->expected(Money::MAX_AMOUNT, 1))->toThrow(OverflowException::class);
    expect(fn () => $cash->expected(0, cashRefunds: Money::MAX_AMOUNT, paidOuts: 1))->toThrow(OverflowException::class);
    expect(fn () => $cash->reconciliation(-Money::MAX_AMOUNT, 1, 0))->toThrow(OverflowException::class);
    expect(fn () => $cash->reconciliation(PHP_INT_MIN, 0, 0))->toThrow(OverflowException::class);
    expect($cash->expected(Money::MAX_AMOUNT, Money::MAX_AMOUNT, cashDrops: Money::MAX_AMOUNT))->toBe(Money::MAX_AMOUNT);
});
