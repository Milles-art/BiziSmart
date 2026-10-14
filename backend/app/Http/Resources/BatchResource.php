<?php

namespace App\Http\Resources;

use App\Services\StockService;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->publicId(), 'product_id' => $this->product->publicId(), 'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->toDateString(), 'qty_remaining' => Quantity::format($this->qty_remaining_atoms, $this->product->quantity_type === 'weight'),
            'cost_price' => $this->when($request->user()?->can('view_buying_price'), fn () => $this->cost_price), 'received_at' => $this->received_at->toISOString(),
            'is_quarantined' => $this->is_quarantined, 'is_recalled' => $this->is_recalled, 'sellable' => app(StockService::class)->isSellable($this->resource), 'receipt' => $this->receipt];
    }
}
