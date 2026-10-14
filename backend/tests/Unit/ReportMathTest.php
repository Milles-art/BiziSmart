<?php
use App\Support\{ReportMath,ReportPeriod,Money};
it('uses inclusive Tanzania calendar dates with exclusive UTC upper bounds',function(){
    $p=new ReportPeriod('2026-10-13','2026-10-13');
    expect($p->start)->toBe('2026-10-12 21:00:00')->and($p->until)->toBe('2026-10-13 21:00:00');
});
it('rejects invalid reversed and unbounded report periods',function($from,$to){
    expect(fn()=>new ReportPeriod($from,$to))->toThrow(InvalidArgumentException::class);
})->with([['2026-02-30','2026-03-01'],['2026-10-14','2026-10-13'],['2025-01-01','2026-01-02'],['2026-1-1','2026-01-02']]);
it('keeps cumulative cost and VAT reversals exact across small and final partial returns',function(){
    $total=0;for($i=0;$i<7;$i++)$total+=ReportMath::reversal(3,7,$i,1);
    expect($total)->toBe(3)->and(ReportMath::reversal(1800,11800,0,5900))->toBe(900)->and(ReportMath::reversal(1800,11800,5900,5900))->toBe(900);
});
it('rejects excess refunds and handles genuine zero-value sale lines',function(){
    expect(ReportMath::reversal(0,0,0,0))->toBe(0);
    expect(fn()=>ReportMath::reversal(10,10,8,3))->toThrow(InvalidArgumentException::class);
});
it('supports negative profit while preserving exact browser-safe bounds',function(){
    expect(ReportMath::add(100,-300))->toBe(-200);
    expect(fn()=>ReportMath::add(Money::MAX_AMOUNT,1))->toThrow(OverflowException::class);
});
