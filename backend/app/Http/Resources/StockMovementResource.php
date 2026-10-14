<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $weighted = $this->product->quantity_type === 'weight';
        return ['id' => $this->publicId(), 'product_id' => $this->product->publicId(), 'batch_id' => $this->batch->publicId(),
            'batch_number' => $this->batch->batch_number, 'type' => $this->type,
            'qty_change' => Quantity::format($this->qty_change_atoms, $weighted), 'qty_after' => Quantity::format($this->qty_after_atoms, $weighted),
            'created_at' => $this->created_at->toISOString(), 'user_id' => $this->user->publicId(), 'device_id' => $this->device?->publicId(), 'receipt' => $this->type === 'receive' ? $this->receipt : null, 'reason' => $this->reason, 'approved_by' => $this->approver?->publicId()];
    }
}
