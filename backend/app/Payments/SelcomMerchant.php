<?php

namespace App\Payments;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final class SelcomMerchant
{
    public function __construct(private TenantContext $context) {}

    public function configuration(Business $business): array
    {
        if (! $business->exists || ! $business->is_active || $business->id !== $this->context->requireId()) {
            throw new AuthorizationException;
        }
        $settings = config('selcom', []);
        if (($settings['business_public_id'] ?? null) !== $business->publicId()) {
            throw $this->unconfigured();
        }
        foreach (['api_key', 'api_secret', 'vendor'] as $key) {
            if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '' || preg_match('/[\x00-\x1f\x7f]/', $settings[$key])) {
                throw $this->unconfigured();
            }
        }
        if (! self::httpsUrl($settings['base_url'] ?? null) || ! self::httpsUrl($settings['webhook_url'] ?? null)) {
            throw $this->unconfigured();
        }
        return $settings;
    }

    public function configured(Business $business): bool
    {
        try { $this->configuration($business); return true; }
        catch (ApiException) { return false; }
    }

    private static function httpsUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }

    private function unconfigured(): ApiException
    {
        return new ApiException('PAYMENT_UNCONFIGURED', 'Mobile payments are not configured for this shop.', 503);
    }
}
