<?php
namespace App\Support;
/** Configured EAN-13 weight labels. No guessed prefix, price segment or vendor protocol. */
class ScaleLabel {
 public static function decode(string $label,array $profile):array {
  if(!preg_match('/^[0-9]{13}$/D',$label))throw new \InvalidArgumentException('Use a 13 digit EAN weight label.');
  $sum=0;for($i=0;$i<12;$i++)$sum+=(int)$label[$i]*($i%2===0?1:3);if((10-$sum%10)%10!==(int)$label[12])throw new \InvalidArgumentException('The scale label check digit is invalid.');
  $prefix=$profile['prefix'];$start=$profile['product_start'];$length=$profile['product_length'];$weightStart=$profile['weight_start'];$weightLength=$profile['weight_length'];
  if(!str_starts_with($label,$prefix))throw new \InvalidArgumentException('This label does not match the configured scale prefix.');
  $grams=(int)substr($label,$weightStart,$weightLength);if($grams<1)throw new \InvalidArgumentException('The scale label weight must be positive.');return ['product_code'=>substr($label,$start,$length),'qty'=>Quantity::format($grams,true),'grams'=>$grams];
 }
 public static function validateProfile(array $profile):void {
  $occupied=[];for($i=0;$i<strlen($profile['prefix']);$i++)$occupied[$i]=true;
  foreach(['product','weight']as$segment){$start=$profile[$segment.'_start'];$length=$profile[$segment.'_length'];if($start+$length>12)throw new \InvalidArgumentException('Scale segments must end before the check digit.');for($i=$start;$i<$start+$length;$i++){if(isset($occupied[$i]))throw new \InvalidArgumentException('The prefix, product and weight segments must not overlap.');$occupied[$i]=true;}}
 }
}
