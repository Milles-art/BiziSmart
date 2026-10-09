<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->publicId(), 'name' => $this->name, 'role' => $this->getRoleNames()->first(),
            'branch_id' => $this->branch?->publicId(), 'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all()];
    }
}
