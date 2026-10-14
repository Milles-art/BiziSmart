<?php
namespace App\Services\Concerns;
use App\Exceptions\ApiException;
use App\Models\{Department, ProductBatch, StockMovement, User};
use App\Support\{ExactMath, Money, Quantity, ReportMath, ReportPeriod};
trait StockLossCounts {
    public function previewLossCount(User $actor,array $input,bool $count): array {
        [$correction,$classification]=$this->lossCountInput($input,$count);
        $review=$this->previewAdjustment($actor,$correction,$classification);
        $batch=$this->adjustmentBatch($input['batch_id']);
        $review['loss_value']=$count ? 0 : $this->lossCostValue(-Quantity::parse($correction['qty_change'],$batch->product->quantity_type==='weight',true),$batch->cost_price,$batch->product->quantity_type==='weight');
        return $review;
    }
    public function commitLossCount(User $actor,#[\SensitiveParameter] array $input,bool $count): array {
        [$correction,$classification]=$this->lossCountInput($input,$count);
        return $this->adjust($actor,$correction,$classification);
    }
    private function lossCountInput(#[\SensitiveParameter] array $input,bool $count): array {
        $batch=$this->adjustmentBatch($input['batch_id']);$weighted=$batch->product->quantity_type==='weight';
        try {
            $quantity=Quantity::parse($input['qty'],$weighted);
            if($count){
                $recorded=isset($input['expected_qty'])&&StockMovement::where('operation_id',strtolower($input['operation_id']))->exists();
                $department=(new Department)->resolveRouteBindingQuery($recorded?Department::withTrashed():Department::query(),$input['department_id'])->first();
                if(!$department || (!$recorded && (int)$department->id !== (int)$batch->product->department_id))throw new ApiException('DEPARTMENT_CHANGED','Select a batch in this department and review its count again.',409);
                $before=isset($input['expected_qty'])?Quantity::parse($input['expected_qty'],$weighted,true):$batch->qty_remaining_atoms;
                if($before===PHP_INT_MIN)throw new \OverflowException('The count variance exceeds the supported exact stock range.');
                $delta=ExactMath::add($quantity,-$before);
                $classification=['type'=>'stock_count','department_id'=>$department->publicId(),'counted_qty'=>Quantity::format($quantity,$weighted)];
            }else{
                if($quantity<1)throw new \InvalidArgumentException('Enter a positive loss quantity.');
                if(!in_array($input['category']??null,['damage','spoilage','wastage'],true))throw new \InvalidArgumentException('Select damage, spoilage or wastage.');
                $delta=-$quantity;$classification=['type'=>'stock_loss','category'=>$input['category']];
            }
        }catch(\InvalidArgumentException|\OverflowException $e){throw new ApiException('VALIDATION_ERROR',$e->getMessage(),422,'qty');}
        return [[...$input,'qty_change'=>Quantity::format($delta,$weighted)],$classification];
    }
    private function lossCostValue(int $atoms,int $cost,bool $weighted):int {
        try{return Money::lineTotal($atoms,$cost,$weighted);}catch(\InvalidArgumentException|\OverflowException){throw new ApiException('VALIDATION_ERROR','The loss cost exceeds the supported exact amount. Record a smaller quantity.',422,'qty');}
    }
    public function losses(array $filters): array {
        $period=new ReportPeriod($filters['from'],$filters['to']);
        $query=StockMovement::with(['product','batch','user','device','approver'])->where('type','stock_loss')->where('created_at','>=',$period->start)->where('created_at','<',$period->until);
        if(isset($filters['department_id'])) {
            if(!(new Department)->resolveRouteBindingQuery(Department::withTrashed(),$filters['department_id'])->first())throw new ApiException('NOT_FOUND','Department not found.',404);
            $query->where('receipt->department_id',$filters['department_id']);
        }
        if(isset($filters['category']))$query->where('receipt->category',$filters['category']);
        $totals=['damage'=>0,'spoilage'=>0,'wastage'=>0];$count=0;
        (clone $query)->chunkById(100,function($rows)use(&$totals,&$count){foreach($rows as $row){$totals[$row->receipt['category']]=ReportMath::add($totals[$row->receipt['category']],$row->receipt['loss_value']);++$count;}});
        $page=$query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page']??50);
        return ['losses'=>array_map(fn($movement)=>[...(new \App\Http\Resources\StockMovementResource($movement))->resolve(),'loss'=>$movement->receipt],$page->items()),'totals'=>$totals,'count'=>$count,'meta'=>['page'=>$page->currentPage(),'per_page'=>$page->perPage(),'total'=>$page->total()],'basis'=>'classified_stock_loss_movements_only'];
    }
}
