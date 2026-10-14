<?php
namespace App\Http\Requests\Suppliers;
use Illuminate\Foundation\Http\FormRequest;
use App\Support\Money;
class SupplierActionRequest extends FormRequest {
    protected function prepareForValidation():void {if($this->routeIs('suppliers.commit'))$this->merge(['idempotency_key'=>$this->header('Idempotency-Key')]);}
    public function authorize():bool{return $this->route('action')==='receive'?($this->user()?->can('receive_stock')??false):($this->user()?->hasRole('owner')??false);}
    public function rules():array {
        $commit=$this->routeIs('suppliers.commit');$action=$this->route('action');
        $money=function($attribute,$value,$fail){try{Money::amount($value);}catch(\InvalidArgumentException|\OverflowException $e){$fail($e->getMessage());}};
        $id=fn($prefix)=>['required','string','regex:/^'.$prefix.'[0-9A-HJKMNP-TV-Z]{26}$/D'];
        $rules=['business_id'=>['prohibited'],'review_hash'=>[$commit?'required':'prohibited','string','regex:/^[a-f0-9]{64}$/D'],'idempotency_key'=>[$commit?'required':'prohibited','uuid']];
        if($action==='supplier')return [...$rules,'supplier_id'=>['sometimes',...$id('sup_')],'expected_revision'=>['sometimes','integer','min:0'],'name'=>['required','string','min:2','max:150'],'phone'=>['sometimes','nullable','string','max:40'],'address'=>['sometimes','nullable','string','max:500'],'is_active'=>['sometimes','boolean']];
        $rules['supplier_id']=$id('sup_');
        if(in_array($action,['order','receive'],true)){
            $rules+=['order_id'=>['sometimes',...$id('po_')],'lines'=>['required','array','min:1','max:100'],'lines.*'=>['array:product_id,packaging_id,qty,cost_price,batch_number,expiry_date'],'lines.*.product_id'=>$id('prod_'),'lines.*.packaging_id'=>['sometimes','nullable','string','regex:/^pkg_[0-9A-HJKMNP-TV-Z]{26}$/D'],'lines.*.qty'=>['required','string','max:32'],'lines.*.cost_price'=>['required',$money]];
            if($action==='order')$rules+=['status'=>['required','in:draft,issued'],'expected_revision'=>['sometimes','integer','min:0'],'lines.*.batch_number'=>['prohibited'],'lines.*.expiry_date'=>['prohibited']];
            else $rules+=['invoice_reference'=>['required','string','min:2','max:150'],'invoice_date'=>['required','date_format:Y-m-d'],'lines.*.batch_number'=>['required','string','min:1','max:80'],'lines.*.expiry_date'=>['sometimes','nullable','date_format:Y-m-d']];
            return $rules;
        }
        if($action==='return')return [...$rules,'invoice_id'=>$id('sinv_'),'reason'=>['required','string','min:3','max:500'],'lines'=>['required','array','min:1','max:100'],'lines.*'=>['array:line_index,qty'],'lines.*.line_index'=>['required','integer','min:0','max:99'],'lines.*.qty'=>['required','string','max:32']];
        $rules+=['allocations'=>['required','array','max:100'],'allocations.*'=>['array:invoice_id,amount'],'allocations.*.invoice_id'=>$id('sinv_'),'allocations.*.amount'=>['required',$money]];
        if($action==='apply_credit')$rules['credit_id']=$id('scredit_');
        elseif($action==='credit')$rules+=['return_id'=>$id('sret_'),'amount'=>['required',$money],'reference'=>['required','string','min:2','max:150'],'completed'=>['required','boolean']];
        elseif($action==='payment')$rules+=['source'=>['required','in:till,bank,mobile'],'till_id'=>['required_if:source,till','prohibited_unless:source,till','string','regex:/^till_[0-9A-HJKMNP-TV-Z]{26}$/D'],'reference'=>['required','string','min:2','max:150'],'paid_on'=>['required','date_format:Y-m-d'],'completed'=>['sometimes','boolean']];
        return $rules;
    }
}
