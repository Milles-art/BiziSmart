<?php
namespace App\Http\Resources;
use App\Support\Quantity;
use Illuminate\Http\Resources\Json\JsonResource;
class SaleResource extends JsonResource {
    public function toArray($request): array {
        $this->resource->loadMissing(['items.product','items.batch','payments','branch','cashier','till']);
        return ['id'=>$this->publicId(), 'receipt_number'=>$this->receipt_number, 'status'=>$this->status,
            'branch_id'=>$this->branch->publicId(), 'cashier_id'=>$this->cashier->publicId(), 'till_id'=>$this->till->publicId(),
            'subtotal'=>$this->subtotal, 'discount'=>$this->discount, 'total'=>$this->total, 'vat_rate'=>$this->vat_rate, 'vat_amount'=>$this->vat_amount,
            'cash_tendered'=>$this->cash_tendered, 'change_given'=>$this->change_given, 'lines'=>$this->lines,
            'customer_id'=>$this->customer_id ? \App\Models\Customer::findOrFail($this->customer_id)->publicId() : null,
            'items'=>$this->items->map(fn ($i)=>['id'=>$i->publicId(), 'product_id'=>$i->product->publicId(), 'batch_id'=>$i->batch->publicId(),
                'line_index'=>$i->line_index, 'base_qty'=>Quantity::format($i->qty_atoms, $i->product->quantity_type === 'weight'), 'unit_price'=>$i->unit_price])->all(),
            'payments'=>$this->payments->map(fn ($p)=>['id'=>$p->publicId(), 'method'=>$p->method, 'amount'=>$p->amount])->all(),
            'receipt_url'=>null, 'created_at'=>$this->created_at->toISOString(),
            'occurred_at'=>$this->occurred_at?->toISOString(), 'is_offline'=>(bool)$this->is_offline];
    }
}
