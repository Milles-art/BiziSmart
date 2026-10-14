<?php
use App\Services\SaleDiscountCalculator;
use App\Support\ExactMath;
it('allocates cart discounts cumulatively and keeps original gross and line discounts',function(){
 $r=(new SaleDiscountCalculator)->apply([['line_total'=>8500,'discount'=>500],['line_total'=>2000,'discount'=>0]],1001);
 expect($r['subtotal'])->toBe(10000)->and($r['total'])->toBe(8999)->and(array_sum(array_column($r['lines'],'line_total')))->toBe(8999);
 expect($r['lines'][0]['gross_total'])->toBe(8500)->and($r['lines'][0]['cart_discount'])->toBe(801)->and($r['lines'][1]['cart_discount'])->toBe(200);
});
it('keeps tiny and zero-money partitions exact at full discounts',function(){
 foreach(range(0,3) as $discount){$r=(new SaleDiscountCalculator)->apply(array_fill(0,3,['line_total'=>1,'discount'=>0]),$discount);expect(array_sum(array_column($r['lines'],'line_total')))->toBe(3-$discount);foreach($r['lines'] as $l)expect($l['line_total'])->toBeGreaterThanOrEqual(0);}
 expect((new SaleDiscountCalculator)->apply([['line_total'=>0,'discount'=>0]],0)['total'])->toBe(0);
});
it('rejects line or cart discounts exceeding exact charges',function(){
 expect(fn()=>(new SaleDiscountCalculator)->apply([['line_total'=>1,'discount'=>2]],0))->toThrow(InvalidArgumentException::class);
 expect(fn()=>(new SaleDiscountCalculator)->apply([['line_total'=>1,'discount'=>0]],2))->toThrow(InvalidArgumentException::class);
});
it('allocates large basket totals without a denominator cap or overflowing products',function(){
 $calculator=new SaleDiscountCalculator;
 expect($calculator->apply([['line_total'=>849991500,'discount'=>0]],0)['total'])->toBe(849991500);
 $r=$calculator->apply([['line_total'=>6000000000000000,'discount'=>0],['line_total'=>3000000000000000,'discount'=>0]],4500000000000001);
 expect($r['lines'][0]['cart_discount'])->toBe(3000000000000001)->and($r['lines'][1]['cart_discount'])->toBe(1500000000000000);
 expect(array_sum(array_column($r['lines'],'line_total')))->toBe($r['total'])->and($r['total'])->toBe(4499999999999999);
});
it('rejects fractional or out-of-range proportional inputs',function(){
 expect(fn()=>ExactMath::roundProportion(1.5,1,2))->toThrow(InvalidArgumentException::class);
 expect(fn()=>ExactMath::roundProportion(1,3,2))->toThrow(InvalidArgumentException::class);
 expect(fn()=>ExactMath::roundProportion(1,0,0))->toThrow(InvalidArgumentException::class);
 expect(ExactMath::roundProportion(PHP_INT_MAX,PHP_INT_MAX,PHP_INT_MAX))->toBe(PHP_INT_MAX);
});
