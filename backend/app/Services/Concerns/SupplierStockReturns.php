<?php
namespace App\Services\Concerns;
use App\Models\{ProductBatch,StockMovement,User};
use App\Support\{ExactMath,Quantity};
use Illuminate\Support\Facades\DB;
trait SupplierStockReturns {
    /** Internal hook: SupplierService has already refreshed owner/business and locked the original invoice. */
    public function returnToSupplier(User $actor,ProductBatch $batch,int $atoms,string $operation,string $reason):void {
        if(DB::transactionLevel()<1)throw new \LogicException('Supplier returns require an authorised transaction.');
        $actor->assertCurrentBusiness();$batch->assertCurrentBusiness();
        if(!$actor->hasRole('owner')||!$actor->can('adjust_stock'))throw new \Illuminate\Auth\Access\AuthorizationException;
        $product=$batch->product;$product->refreshForUpdate();$batch->refreshForUpdate();
        if($atoms<1||$atoms>$batch->qty_remaining_atoms)throw new \App\Exceptions\ApiException('INSUFFICIENT_STOCK','The original delivery does not contain enough recorded stock to return.',400);
        $batch->qty_remaining_atoms=ExactMath::add($batch->qty_remaining_atoms,-$atoms);$batch->save();
        $weighted=$product->quantity_type==='weight';
        $receipt=['product_id'=>$product->publicId(),'product_name'=>$product->name,'batch_id'=>$batch->publicId(),'batch_number'=>$batch->batch_number,
            'quantity_type'=>$product->quantity_type,'unit'=>$product->unit,'qty_returned'=>Quantity::format($atoms,$weighted),'qty_after'=>Quantity::format($batch->qty_remaining_atoms,$weighted),
            'cost_price'=>$batch->cost_price,'operation_id'=>$operation,'recorded_at'=>now()->toISOString()];
        StockMovement::create(['product_id'=>$product->id,'batch_id'=>$batch->id,'type'=>'supplier_return','qty_change_atoms'=>-$atoms,'qty_after_atoms'=>$batch->qty_remaining_atoms,'user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>$operation,'reason'=>$reason,'approved_by'=>$actor->id,'receipt'=>$receipt]);
    }
}
