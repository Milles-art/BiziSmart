<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $weighted = $this->quantity_type === 'weight';
        $stock = app(\App\Services\StockService::class)->summary($this->resource);
        return [
            'id' => $this->publicId(), 'image'=>app(\App\Services\ProductImageService::class)->metadata($this->resource), 'name' => $this->name, 'barcode' => $this->barcode, 'category' => $this->category,
            'department_id' => $this->department?->publicId(), 'department_name' => $this->department?->name,
            'quantity_type' => $this->quantity_type, 'unit' => $this->unit,
            'quantity_precision' => $weighted ? 3 : 0, 'quantity_scale' => $weighted ? 1000 : 1,
            'selling_price' => $this->selling_price,
            'buying_price' => $this->when($request->user()?->can('view_buying_price'), fn () => $this->buying_price),
            'reorder_level' => Quantity::format($this->reorder_level_atoms, weighted: $weighted),
            'tracks_expiry' => $this->tracks_expiry, 'is_active' => $this->is_active,
            'stock' => $stock['stock'], 'nearest_expiry' => $stock['nearest_expiry'], 'stock_available' => true,
            'stock_total' => $this->when($request->user()?->can('receive_stock'), fn () => $stock['stock_total']),
            'packagings' => PackagingResource::collection($this->whenLoaded('activePackagings')),
            'selected_packaging' => $this->relationLoaded('selectedPackaging') && $this->selectedPackaging ? new PackagingResource($this->selectedPackaging) : null,
        ];
    }
}
