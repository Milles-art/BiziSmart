<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ExpiryAlertResource extends BatchResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'product_name' => $this->product->name, 'unit' => $this->product->unit, 'quantity_type' => $this->product->quantity_type];
    }
}
