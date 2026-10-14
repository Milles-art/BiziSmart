<?php
namespace App\Support;
final class PricingMath {
 public static function roundScaledProduct(int $left,int $right,int $denominator):int {
  if($left<0||$right<0||$denominator<1)throw new \InvalidArgumentException('Positive pricing denominator and nonnegative operands are required.');
  return ExactMath::add(ExactMath::multiply(intdiv($left,$denominator),$right),ExactMath::roundProportion($right,$left%$denominator,$denominator));
 }
 public static function percentageNet(int $gross,int $basisPoints):int {
  Money::amount($gross);if($basisPoints<1||$basisPoints>10000)throw new \InvalidArgumentException('Discount basis points must be between 1 and 10000.');
  return Money::amount(ExactMath::roundProductRatio($gross,10000-$basisPoints,10000));
 }
}
