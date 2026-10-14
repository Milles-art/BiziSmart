<?php
namespace App\Http\Resources;
use App\Models\PricingConsumption;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PricingRuleResource extends JsonResource {
 public function toArray(Request $request):array{$this->resource->loadMissing(['targets.product','targets.packaging','targets.batch']);$first=$this->targets->first();$weighted=$first?->product?->quantity_type==='weight';return ['id'=>$this->publicId(),'name'=>$this->name,'kind'=>$this->kind,'priority'=>$this->priority,'price'=>$this->price,'basis_points'=>$this->basis_points,'buy_qty'=>$this->buy_qty,'free_qty'=>$this->free_qty,'max_qty'=>$this->max_atoms!==null?Quantity::format($this->max_atoms,$weighted):null,'used_qty'=>$this->max_atoms!==null?Quantity::format((int)PricingConsumption::where('pricing_rule_id',$this->id)->sum('qty_atoms'),$weighted):null,'starts_at'=>$this->starts_at->toIso8601String(),'ends_at'=>$this->ends_at?->toIso8601String(),'cancelled_at'=>$this->cancelled_at?->toIso8601String(),'created_at'=>$this->created_at->toIso8601String(),'reason'=>$this->reason,'targets'=>$this->targets->map(fn($t)=>['product_id'=>$t->product->publicId(),'product_name'=>$t->product->name,'packaging_id'=>$t->packaging?->publicId(),'packaging_name'=>$t->packaging?->name,'packaging_version'=>$t->packaging?->version,'batch_id'=>$t->batch?->publicId(),'batch_number'=>$t->batch?->batch_number,'unit'=>$t->product->unit])->all()];}
}
