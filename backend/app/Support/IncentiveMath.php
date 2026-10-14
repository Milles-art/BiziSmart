<?php
namespace App\Support;
final class IncentiveMath {
 public static function earned(int $total,int $vat,int $paid,?int $tshPerPoint,?int $commissionBasisPoints):array{
  Money::amount($total);Money::amount($vat);Money::amount($paid);if($vat>$total||$paid>$total)throw new \InvalidArgumentException('Incentive paid value and VAT must fit the original sale.');
  $net=$total>0?Money::amount(ExactMath::roundProportion($total-$vat,$paid,$total)):0;
  if($tshPerPoint!==null&&$tshPerPoint<1)throw new \InvalidArgumentException('A positive earning unit is required.');
  if($commissionBasisPoints!==null&&($commissionBasisPoints<1||$commissionBasisPoints>10000))throw new \InvalidArgumentException('Commission basis points must be 1 to 10000.');
  return ['paid_net_value'=>$net,'points_total'=>$tshPerPoint?intdiv($net,$tshPerPoint):0,'commission_total'=>$commissionBasisPoints?Money::amount(ExactMath::roundProductRatio($net,$commissionBasisPoints,10000)):0];
 }
 public static function ceilingDemand(int $atoms,int $days,int $coverageDays):int{
  if($atoms<0||$days<1||$coverageDays<1)throw new \InvalidArgumentException('Demand and windows must be nonnegative/positive.');$whole=ExactMath::multiply(intdiv($atoms,$days),$coverageDays);$remainder=ExactMath::multiply($atoms%$days,$coverageDays);return ExactMath::add($whole,intdiv($remainder,$days)+($remainder%$days?1:0));
 }
}
