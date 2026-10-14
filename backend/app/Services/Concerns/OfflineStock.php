<?php
namespace App\Services\Concerns;

use App\Models\{Device, Product, ProductBatch, Sale, SaleItem, StockMovement, User};
use App\Support\ExactMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait OfflineStock
{
    /** OfflineSaleService authenticates the lease, locks the shop/products and supplies trusted cache batch IDs. */
    public function allocateOfflineSale(User $actor, Device $device, Product $product, Sale $sale,
        int $atoms, int $lineIndex, int $price, array $cachedBatchIds): array
    {
        $actor->assertCurrentBusiness(); $device->assertCurrentBusiness(); $product->assertCurrentBusiness(); $sale->assertCurrentBusiness();
        if (DB::transactionLevel() < 1 || $atoms < 1 || !$cachedBatchIds) throw new \LogicException('Offline allocations require a verified cached batch and domain transaction.');
        $batches=ProductBatch::where('product_id',$product->id)->whereIn('id',$cachedBatchIds)
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
        if ($batches->isEmpty()) throw new \LogicException('A verified offline batch no longer exists.');
        $remaining=$atoms; $conflicts=[];
        $write=function(ProductBatch $batch,int $taken)use($actor,$device,$product,$sale,$lineIndex,$price,&$conflicts){
            $after=ExactMath::add($batch->qty_remaining_atoms,-$taken);
            if($after<0)$conflicts[]='NEGATIVE_STOCK';
            if($batch->expiry_date && $batch->expiry_date->toDateString()<now('Africa/Dar_es_Salaam')->toDateString())$conflicts[]='EXPIRED_BATCH';
            if($batch->is_recalled)$conflicts[]='RECALLED_BATCH';
            if($batch->is_quarantined)$conflicts[]='QUARANTINED_BATCH';
            $batch->qty_remaining_atoms=$after; $batch->save();
            SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$product->id,'batch_id'=>$batch->id,'line_index'=>$lineIndex,
                'qty_atoms'=>$taken,'unit_price'=>$price,'cost_price'=>$batch->cost_price]);
            StockMovement::create(['product_id'=>$product->id,'batch_id'=>$batch->id,'sale_id'=>$sale->id,'type'=>'sale',
                'qty_change_atoms'=>-$taken,'qty_after_atoms'=>$after,'user_id'=>$actor->id,'device_id'=>$device->id,
                'operation_id'=>(string)Str::uuid(),'receipt'=>['sale_id'=>$sale->publicId(),'line_index'=>$lineIndex,'offline'=>true]]);
        };
        foreach($batches as $batch){
            if($remaining===0)break;
            $taken=min($remaining,max(0,$batch->qty_remaining_atoms));
            if($taken>0){$write($batch,$taken);$remaining-=$taken;}
        }
        // A counter sale already happened. Record the unfilled balance on the oldest trusted cached batch and flag it.
        if($remaining>0)$write($batches->first(),$remaining);
        return array_values(array_unique($conflicts));
    }
}
