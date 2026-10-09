<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\OwnerSetupService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OwnerInvite extends Command
{
    protected $signature = 'bizismart:owner-invite {shop} {--name=}';
    protected $description = 'Save a private, one-use initial owner setup link for a local shop';
    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) { $this->error('Use verified platform onboarding outside local/testing.'); return self::FAILURE; }
        $business = Business::where('subdomain', $this->argument('shop'))->where('is_active', true)->firstOrFail();
        app(TenantContext::class)->run($business, function () use ($business) {
            $token = app(OwnerSetupService::class)->invite();
            if ($this->option('name')) { $business->name = $this->option('name'); $business->save(); }
            $path = storage_path('app/private/owner-setup-'.$business->publicId().'.json');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode(['url' => 'http://'.$business->subdomain.'.'.config('tenancy.domain').':15173/#setup='.$token]));
            $this->info('Owner setup link saved in private application storage. It expires in 90 minutes.');
        });
        return self::SUCCESS;
    }
}
