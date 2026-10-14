<?php
namespace App\Http\Requests\Pricing;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
class SavePricingRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->hasRole('owner')??false;}
 public function rules():array{return ['operation_id'=>['required','uuid'],'name'=>['required','string','max:160'],'kind'=>['required','in:schedule,fixed_price,percentage,buy_get,markdown'],'priority'=>['required','integer','min:0','max:10000'],'reason'=>['required','string','max:1000'], 'starts_at'=>['required','date_format:Y-m-d\TH:i'],'ends_at'=>['nullable','date_format:Y-m-d\TH:i'], 'price'=>['nullable',function($a,$v,$f){try{Money::amount($v);}catch(\InvalidArgumentException|\OverflowException $e){$f($e->getMessage());}}],'basis_points'=>['nullable','integer','min:1','max:10000'],'buy_qty'=>['nullable','integer','min:1','max:100000'],'free_qty'=>['nullable','integer','min:1','max:100000'],'max_qty'=>['nullable','string','max:32'],'targets'=>['required','array','min:1','max:50'],'targets.*'=>['required','array:product_id,packaging_id,batch_id'],'targets.*.product_id'=>['required','string','regex:/^prod_[0-9A-HJKMNP-TV-Z]{26}$/D'],'targets.*.packaging_id'=>['nullable','string','regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],'targets.*.batch_id'=>['nullable','string','regex:/^bat_[0-9A-HJKMNP-TV-Z]{26}$/D'],'review_hash'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D'],'business_id'=>['prohibited']];}
}
