<?php

namespace App\Http\Resources;

use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackagingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->publicId(), 'product_id' => $this->product->publicId(), 'name' => $this->name,
            'base_qty' => Quantity::format($this->conversion_atoms, $this->product->quantity_type === 'weight'),
            'conversion_atoms' => (string) $this->conversion_atoms, 'selling_price' => $this->selling_price,
            'barcode' => $this->barcode, 'version' => $this->version, 'is_active' => $this->is_active, 'archived' => $this->archived_at !== null];
    }
}
