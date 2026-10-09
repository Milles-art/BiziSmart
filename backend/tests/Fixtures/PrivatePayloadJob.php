<?php

namespace Tests\Fixtures;

use App\Jobs\TenantJob;

class PrivatePayloadJob extends TenantJob
{
    public function __construct(int $businessId, private mixed $payload)
    {
        parent::__construct($businessId);
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function handle(): void {}
}
