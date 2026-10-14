<?php
namespace App\Services;
use App\Support\{ExactMath,Money};
final class SaleDiscountCalculator {
    /** Cumulative proportional rounding keeps the cart discount exact and every line non-negative. */
    public function apply(array $lines, int $cartDiscount): array {
        $cartDiscount=Money::amount($cartDiscount);
        $subtotal=0;
        foreach($lines as &$line){
            $gross=Money::amount($line['line_total']);$automatic=Money::amount($line['automatic_discount']??0);if($automatic>$gross)throw new \InvalidArgumentException('Automatic savings cannot exceed the regular gross.');$regular=$gross;$gross-=$automatic;$discount=Money::amount($line['discount']??0);
            if($discount>$gross)throw new \InvalidArgumentException('A line discount cannot exceed its price.');
            $line['gross_total']=$regular;$line['line_discount']=$discount;
            $line['line_total']=$gross-$discount;$subtotal=Money::amount(ExactMath::add($subtotal,$line['line_total']));
        }unset($line);
        if($cartDiscount>$subtotal)throw new \InvalidArgumentException('The cart discount cannot exceed the subtotal.');
        $cumulative=0;$previous=0;
        foreach($lines as &$line){
            $cumulative=ExactMath::add($cumulative,$line['line_total']);
            $allocated=$subtotal>0?ExactMath::roundProportion($cartDiscount,$cumulative,$subtotal):0;
            $share=$allocated-$previous;$previous=$allocated;
            $line['cart_discount']=$share;$line['discount']=ExactMath::add($line['line_discount'],$share);$line['line_total']-=$share;
        }unset($line);
        return ['lines'=>$lines,'subtotal'=>$subtotal,'discount'=>$cartDiscount,'total'=>$subtotal-$cartDiscount];
    }
}
