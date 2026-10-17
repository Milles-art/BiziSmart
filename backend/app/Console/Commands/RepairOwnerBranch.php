<?php

namespace App\Console\Commands;

use App\Models\{Branch, Business, TillSession, User};
use App\Services\StaffManagementService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairOwnerBranch extends Command
{
    protected $signature = 'bizismart:owner-branch-repair {shop} {--apply : Apply the guarded assignment; otherwise inspect only}';
    protected $description = 'Repair an unassigned initial owner only when exactly one active owner and branch exist';

    public function handle(): int
    {
        $shop = Business::where('subdomain', $this->argument('shop'))->where('is_active', true)->first();
        if (! $shop) { $this->error('Active shop not found. No changes.'); return self::FAILURE; }

        return app(TenantContext::class)->run($shop, fn () => DB::transaction(function () use ($shop) {
            $lockedShop = Business::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if (! $lockedShop->is_active) { $this->error('Shop is inactive. No changes.'); return self::FAILURE; }
            $owners = User::where('is_active', true)->whereHas('roles', fn ($query) => $query->where('name', 'owner'))
                ->lockForUpdate()->limit(2)->get();
            if ($owners->count() !== 1) { $this->error('Exactly one active owner is required. No changes.'); return self::FAILURE; }
            $owner = $owners->first();
            if ($owner->branch_id !== null) { $this->info('Owner already has a branch assignment. No changes.'); return self::SUCCESS; }
            $branches = Branch::where('is_active', true)->lockForUpdate()->limit(2)->get();
            if ($branches->count() !== 1) { $this->error('Exactly one active branch is required. No changes.'); return self::FAILURE; }
            if (TillSession::where('cashier_id', $owner->id)->where('status', 'open')->exists()) {
                $this->error('Owner has an open till. No changes.'); return self::FAILURE;
            }
            $branch = $branches->first();
            if (! $this->option('apply')) {
                $this->info('Ready: '.$owner->publicId().' -> '.$branch->publicId().' ('.$branch->name.').');
                $this->info('Inspection only. No changes. Use --apply to assign and revoke existing owner sessions.');
                return self::SUCCESS;
            }
            app(StaffManagementService::class)->update($owner, $owner, ['branch_id' => $branch->publicId()]);
            activity('staff')->performedOn($owner)->event('owner_branch_repaired')
                ->withProperties(['source' => 'trusted_console', 'branch_id' => $branch->publicId()])
                ->log('Unassigned initial owner branch repaired through console');
            $this->info('Owner assigned to '.$branch->name.'. Sign in again; PIN and authenticator are unchanged.');
            return self::SUCCESS;
        }, 3));
    }
}
