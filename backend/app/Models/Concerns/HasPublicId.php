<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(fn ($model) => $model->public_id = (string) Str::ulid());
    }

    public function publicId(): string
    {
        return static::PUBLIC_ID_PREFIX.$this->public_id;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function getRouteKey(): mixed
    {
        return $this->publicId();
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $prefix = preg_quote(static::PUBLIC_ID_PREFIX, '/');
        if (! is_string($value) || ! preg_match('/^'.$prefix.'([0-9A-HJKMNP-TV-Z]{26})$/D', $value, $match)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where($field ?? 'public_id', $match[1]);
    }
}
