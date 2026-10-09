<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ManagedStaffResource extends StaffResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'phone' => $this->phone, 'is_active' => $this->is_active,
            'must_change_pin' => $this->must_change_pin, 'two_factor_enabled' => $this->totp_confirmed_at !== null];
    }
}
