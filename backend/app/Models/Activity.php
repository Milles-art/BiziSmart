<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;

class Activity extends \Spatie\Activitylog\Models\Activity
{
    use BelongsToBusiness;

    protected $casts = ['properties' => 'encrypted:collection'];
    protected $hidden = ['id', 'business_id', 'subject_id', 'causer_id', 'properties'];

    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            foreach (['subject', 'causer'] as $relation) {
                if ($activity->{$relation.'_id'} !== null) {
                    $model = $activity->{$relation};
                    if (! $model || ! method_exists($model, 'assertCurrentBusiness')) {
                        throw new \LogicException('Audit associations must belong to the current shop.');
                    }
                    $model->assertCurrentBusiness();
                }
            }
            if (request()->route()) {
                $token = \Illuminate\Support\Facades\Auth::guard('sanctum')->user()?->currentAccessToken();
                $device = $token?->device_id ? Device::find($token->device_id) : null;
                $activity->properties = ($activity->properties ?? collect())->merge([
                    'request_id' => request()->attributes->get('request_id'), 'ip' => request()->ip(), 'device_id' => $device?->publicId(),
                ]);
            }
        });
    }
}
