<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class ProductPriceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->publicId(), 'product_name' => $this->product_name, 'packaging_id' => $this->packaging?->publicId(),
            'packaging_name' => $this->packaging_name, 'packaging_version' => $this->packaging_version,
            'unit' => $this->unit, 'base_qty' => $this->base_qty, 'old_price' => $this->old_price, 'new_price' => $this->new_price,
            'actor_id' => $this->actor->publicId(), 'actor_name' => $this->actor->name, 'created_at' => $this->created_at->toIso8601String(),
            'source' => $this->source ?? 'catalogue', 'source_id' => $this->source_id];
    }
}
