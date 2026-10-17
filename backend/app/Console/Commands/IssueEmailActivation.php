<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Services\EmailAuthService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class IssueEmailActivation extends Command
{
    protected $signature = 'bizismart:email-activate {subdomain} {user : Exact public usr_ identifier}';
    protected $description = 'Issue a private one-use email sign-in activation for an existing verified staff account';

    public function handle(TenantContext $context): int
    {
        $business = Business::where('subdomain', $this->argument('subdomain'))->where('is_active', true)->first();
        if (! $business || ! preg_match('/^usr_[0-9A-HJKMNP-TV-Z]{26}$/D', $this->argument('user'))) {
            $this->error('An active shop and exact public staff identifier are required.');
            return self::FAILURE;
        }
        return $context->run($business, function () {
            $user = (new User)->resolveRouteBinding($this->argument('user'));
            if (! $user || ! $user->is_active || $user->usesEmailPassword()) {
                $this->error('This active account cannot be activated. Use email password reset after activation.');
                return self::FAILURE;
            }
            $auth = app(EmailAuthService::class);
            $plain = $auth->createActivationToken($user);
            $this->warn('Private one-use link; expires in 30 minutes. Give only to the verified account holder.');
            $this->line($auth->trustedLink('activate', $plain));
            return self::SUCCESS;
        });
    }
}
