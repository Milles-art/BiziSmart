<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use LogicException;

class PersonalAccessToken extends SanctumToken
{
    use BelongsToBusiness;

    protected $hidden = ['id', 'business_id', 'token', 'tokenable_id', 'device_id'];

    protected static function booted(): void
    {
        static::creating(function (PersonalAccessToken $token): void {
            if ($token->tokenable_type !== User::class || ! $token->tokenable instanceof User) {
                throw new LogicException('A token must belong to a user in the current business.');
            }
            $token->tokenable->assertCurrentBusiness();
        });
    }
}
