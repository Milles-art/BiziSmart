<?php

namespace App\Services;

use App\Models\{Business, User};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class BusinessSettingsService
{
    public function __construct(private TenantContext $context, private FinancialActorService $actors) {}

    public function read(): array
    {
        return $this->profile(Business::whereKey($this->context->requireId())->firstOrFail());
    }

    public function save(User $original, array $input): array
    {
        return DB::transaction(function () use ($original, $input) {
            $actor = $this->actors->lock($original, null);
            if (! $actor->hasRole('owner')) throw new AuthorizationException;
            $shop = Business::whereKey($this->context->requireId())->where('is_active', true)->lockForUpdate()->firstOrFail();
            $shop->fill(array_intersect_key($input, array_flip(['name', 'address', 'phone', 'tin', 'vrn'])));
            $settings = $shop->settings ?? [];
            $settings['receipt'] = array_intersect_key($input, array_flip(['header', 'footer', 'paper_width']));
            $shop->settings = $settings;
            $shop->save();
            activity('settings')->causedBy($actor)->event('business_profile_updated')
                ->withProperties(['fields' => array_keys($input)])->log('Business and receipt profile updated');
            return $this->profile($shop);
        }, 3);
    }

    private function profile(Business $shop): array
    {
        $receipt = $shop->settings['receipt'] ?? [];
        return ['name' => $shop->name, 'address' => $shop->address, 'phone' => $shop->phone,
            'tin' => $shop->tin, 'vrn' => $shop->vrn, 'header' => $receipt['header'] ?? '',
            'footer' => $receipt['footer'] ?? '', 'paper_width' => $receipt['paper_width'] ?? '80',
            'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam'];
    }
}
