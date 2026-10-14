<?php

use App\Services\RefundCalculator;
use App\Support\Money;

it('reduces the selected invoice debt first and caps cash including prior collections', function () {
    $refund = new RefundCalculator;
    expect($refund->cashSettlement(5000, 10000, 10000))->toBe(['debt_reduction' => 5000, 'cash_refund' => 0]);
    expect($refund->cashSettlement(10000, 6000, 14000))->toBe(['debt_reduction' => 6000, 'cash_refund' => 4000]);
    expect($refund->cashSettlement(20000, 6000, 14000))->toBe(['debt_reduction' => 6000, 'cash_refund' => 14000]);
    expect($refund->cashSettlement(10000, 0, 10000))->toBe(['debt_reduction' => 0, 'cash_refund' => 10000]);
    expect($refund->cashSettlement(0, 0, 0))->toBe(['debt_reduction' => 0, 'cash_refund' => 0]);
    expect(fn () => $refund->cashSettlement(20001, 6000, 14000))->toThrow(InvalidArgumentException::class);
});

it('refunds partial discounted lines using cumulative half-up amounts', function () {
    $refund = new RefundCalculator;
    expect([$refund->line(2, 3, 0, 1), $refund->line(2, 3, 1, 1), $refund->line(2, 3, 2, 1)])->toBe([1, 0, 1]);
    expect([$refund->line(1, 2, 0, 1), $refund->line(1, 2, 1, 1)])->toBe([1, 0]);
    expect($refund->line(8500, 1750, 0, 750))->toBe(3643);
    expect($refund->line(8500, 1750, 750, 1000))->toBe(4857);
    expect($refund->line(5000, 6, 0, 1))->toBe(833);
    expect($refund->line(5000, 6, 1, 1))->toBe(834);
});

it('keeps full refunds exact across every partition of small counted lines', function () {
    $refund = new RefundCalculator;
    for ($price = 0; $price < 20; $price++) {
        for ($sold = 1; $sold < 24; $sold++) {
            $sum = 0;
            for ($before = 0; $before < $sold; $before++) {
                $sum += $refund->line($price, $sold, $before, 1);
            }
            expect($sum)->toBe($price);
        }
    }
});

it('handles large gram denominators without losing a shilling or overflowing', function () {
    $refund = new RefundCalculator;
    $half = intdiv(PHP_INT_MAX, 2);
    expect($refund->line(Money::MAX_AMOUNT, PHP_INT_MAX, 0, $half))->toBe(4503599627370495);
    expect($refund->line(Money::MAX_AMOUNT, PHP_INT_MAX, 0, $half + 1))->toBe(4503599627370496);
    expect($refund->line(Money::MAX_AMOUNT, Money::MAX_AMOUNT, 0, Money::MAX_AMOUNT - 1))->toBe(Money::MAX_AMOUNT - 1);
    expect($refund->line(Money::MAX_AMOUNT, PHP_INT_MAX, 0, PHP_INT_MAX))->toBe(Money::MAX_AMOUNT);
});

it('rejects excessive zero and negative return quantities', function (int $sold, int $before, int $qty) {
    expect(fn () => (new RefundCalculator)->line(100, $sold, $before, $qty))->toThrow(InvalidArgumentException::class);
})->with([[0, 0, 1], [3, -1, 1], [3, 4, 1], [3, 0, 0], [3, 2, 2], [3, 0, -1]]);

 it('splits paid refunds exactly after reducing debt and never exceeds either remaining method',function(){
  $calculator=new \App\Services\RefundCalculator;
  expect($calculator->splitSettlement(8500,0,2500,6000))->toBe(['debt_reduction'=>0,'cash_refund'=>2500,'mobile_refund'=>6000]);
  expect($calculator->splitSettlement(4000,1500,2000,5000))->toBe(['debt_reduction'=>1500,'cash_refund'=>714,'mobile_refund'=>1786]);
  $cash=2500;$mobile=6000;
  foreach([1,2,8497] as $value){$part=$calculator->splitSettlement($value,0,$cash,$mobile);expect($part['cash_refund']+$part['mobile_refund'])->toBe($value);$cash-=$part['cash_refund'];$mobile-=$part['mobile_refund'];expect($cash)->toBeGreaterThanOrEqual(0);expect($mobile)->toBeGreaterThanOrEqual(0);}
  expect([$cash,$mobile])->toBe([0,0]);expect(fn()=>$calculator->splitSettlement(2,0,1,0))->toThrow(InvalidArgumentException::class);
 });
