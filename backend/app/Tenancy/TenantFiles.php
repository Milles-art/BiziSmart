<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Storage;

class TenantFiles
{
    public function __construct(private TenantContext $context) {}

    public function put(string $path, string $contents): bool
    {
        return Storage::disk('local')->put($this->context->filePath($path), $contents);
    }

    public function get(string $path): string
    {
        return Storage::disk('local')->get($this->context->filePath($path));
    }
}
