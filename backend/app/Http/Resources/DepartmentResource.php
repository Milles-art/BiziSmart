<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->publicId(), 'name' => $this->name,
            'assigned_products_count' => $this->when($request->user()?->can('manage_products') && isset($this->assigned_products_count), fn () => $this->assigned_products_count)];
    }
}
