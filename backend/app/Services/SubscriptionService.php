<?php

namespace App\Services;

use App\Models\Subscription;

/**
 * Single authority for the tenant subscription rule used by the API status
 * endpoint, the subscription middleware and messaging renewals: a shop with
 * no subscription row is unmanaged but active; a managed row must be active
 * with a started, not-yet-ended period.
 */
class SubscriptionService
{
    public function current(): ?Subscription
    {
        return Subscription::first();
    }

    public function active(?Subscription $subscription = null): bool
    {
        $s = $subscription ?? $this->current();

        if ($s === null) {
            return true;
        }

        return $s->status === 'active'
            && ! $s->starts_at->isFuture()
            && $s->ends_at->isFuture();
    }

    /**
     * Read-only payload for GET /subscription-status (contract in docs/04).
     *
     * @return array{managed: bool, active: bool, status: string|null, ends_at: string|null}
     */
    public function status(): array
    {
        $s = $this->current();

        return [
            'managed' => $s !== null,
            'active' => $this->active($s),
            'status' => $s?->status,
            'ends_at' => $s?->ends_at?->toISOString(),
        ];
    }
}
