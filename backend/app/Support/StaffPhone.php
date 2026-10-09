<?php

namespace App\Support;

use InvalidArgumentException;

class StaffPhone
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s-]+/', '', $phone);
        if (preg_match('/^0[0-9]{9}$/D', $phone)) { return '255'.substr($phone, 1); }
        if (preg_match('/^\+?255[0-9]{9}$/D', $phone)) { return ltrim($phone, '+'); }
        throw new InvalidArgumentException('A Tanzania phone number is required.');
    }
}
