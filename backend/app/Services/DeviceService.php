<?php

namespace App\Services;

use App\Models\AuthChallenge;
use App\Models\Business;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DeviceService
{
    public function setStatus(User $actor, Device $device, string $status): void
    {
        $actor->assertCurrentBusiness();
        $device->assertCurrentBusiness();
        if (! $actor->is_active || ! $actor->hasRole('owner')) { throw new AuthorizationException; }
        if (! in_array($status, ['approved', 'blocked'], true)) { throw new \InvalidArgumentException('Invalid device status.'); }
        DB::transaction(function () use ($actor, $device, $status) {
            $actor->refreshForUpdate();
            if (! $actor->is_active || ! $actor->hasRole('owner')) { throw new AuthorizationException; }
            Business::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
            $device->refreshForUpdate();
            $device->status = $status;
            $device->approved_by = $status === 'approved' ? $actor->id : null;
            $device->save();
            if ($status === 'blocked') {
                PersonalAccessToken::where('device_id', $device->id)->delete();
                AuthChallenge::where('device_id', $device->id)->delete();
            }
        }, 3);
    }
}
