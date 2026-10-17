<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AuthChallenge;
use App\Models\Business;
use App\Models\EmailCredentialToken;
use App\Models\OfflineLease;
use App\Models\SupervisorApproval;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class EmailAuthService
{
    public function __construct(private TenantContext $context, private StaffAuthService $sessions, private OwnerSetupService $owners) {}

    public function login(#[\SensitiveParameter] array $input, ?string $ip): array
    {
        $this->assertPassword($input['password']);
        $dummy = Hash::make(bin2hex(random_bytes(32)));
        $result = DB::transaction(function () use ($input, $ip, $dummy) {
            $user = User::forEmail($input['email'])->lockForUpdate()->first();
            $valid = Hash::check($input['password'], $user?->password_hash ?? $dummy);
            if (! $user || ! $user->is_active || ! $user->password_hash) { return $this->invalidCredentials(); }
            if ($user->locked_until?->isFuture()) { return $this->locked(); }
            if ($user->locked_until !== null) { $user->failed_pin_attempts = 0; $user->locked_until = null; }
            if (! $valid) {
                $user->failed_pin_attempts++;
                if ($user->failed_pin_attempts >= 5) { $user->locked_until = now()->addMinutes(15); }
                $user->save();
                return $user->locked_until ? $this->locked() : $this->invalidCredentials();
            }
            $user->failed_pin_attempts = 0;
            $user->locked_until = null;
            $user->save();
            try { return $this->sessions->issueEmailSession($user, $input['device_uid'], $input['device_name'], $ip); }
            catch (ApiException $e) { return $e; }
        }, 3);
        if ($result instanceof ApiException) { throw $result; }
        return $result;
    }

    public function setup(#[\SensitiveParameter] array $input, ?string $ip): array
    {
        // The private shop invitation already has the approved owner contact.
        // Registration needs only name/email/password in the current interface.
        $input['phone'] ??= Business::findOrFail($this->context->requireId())->owner_phone;
        // The original invitation/scaffold/branch rules remain authoritative. The
        // generated unknown legacy PIN is never returned, and email-enabled users
        // cannot use the legacy public PIN login.
        DB::transaction(function () use ($input) {
            $this->owners->register([...$input, 'pin' => bin2hex(random_bytes(32))]);
            $owner = User::forPhone($input['phone'])->lockForUpdate()->firstOrFail();
            $this->setCredentials($owner, $input['email'], $input['password']);
        }, 3);
        return $this->login($input, $ip);
    }

    public function enroll(User $actor, #[\SensitiveParameter] array $input): void
    {
        $actor->assertCurrentBusiness();
        $result = DB::transaction(function () use ($actor, $input) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            // Recheck the authenticating token after acquiring the user lock so a
            // concurrent reset cannot authorize a credential change.
            $token = $actor->currentAccessToken();
            if (! $user->is_active || ! $token || ! $user->tokens()->whereKey($token->id)->exists() || $user->password_hash) {
                return new ApiException('CREDENTIAL_ENROLLMENT_UNAVAILABLE', 'Use your email sign-in or a new private activation link.', 409);
            }
            if ($user->locked_until?->isFuture()) { return $this->locked(); }
            if (! Hash::check($input['current_pin'], $user->pin_hash)) {
                $user->failed_pin_attempts++;
                if ($user->failed_pin_attempts >= 5) { $user->locked_until = now()->addMinutes(15); }
                $user->save();
                return $user->locked_until ? $this->locked() : $this->invalidCredentials();
            }
            $this->setCredentials($user, $input['email'], $input['password']);
            return null;
        }, 3);
        if ($result instanceof ApiException) { throw $result; }
    }

    /** Trusted CLI or separately authorized owner action; never accept a user ID from a public reset form. */
    public function createActivationToken(User $target): string
    {
        $target->assertCurrentBusiness();
        return DB::transaction(function () use ($target) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_active || $user->password_hash) {
                throw new ApiException('CREDENTIAL_ENROLLMENT_UNAVAILABLE', 'Use your email sign-in or password reset.', 409);
            }
            return $this->newToken($user, 'activation');
        }, 3);
    }

    public function activationLink(User $actor, string $publicId): array
    {
        $actor->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $publicId) {
            $actor->refreshForUpdate();
            $token = $actor->currentAccessToken();
            if (! $actor->is_active || ! $actor->hasRole('owner') || ! $token || ! $actor->tokens()->whereKey($token->id)->exists()) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $target = (new User)->resolveRouteBinding($publicId) ?? abort(404);
            $plain = $this->createActivationToken($target);
            activity('staff')->performedOn($target)->causedBy($actor)->event('email_activation_issued')->log('Private sign-in activation issued');
            return ['setup_url' => $this->trustedLink('activate', $plain), 'expires_at' => now()->addMinutes(30)->toISOString()];
        }, 3);
    }

    public function activate(#[\SensitiveParameter] array $input): void
    {
        $this->consume($input['token'], 'activation', function (User $user) use ($input) {
            if ($user->password_hash) { throw $this->invalidToken(); }
            $this->setCredentials($user, $input['email'], $input['password']);
        });
    }

    public function forgot(#[\SensitiveParameter] string $email): void
    {
        // No token, link or transport diagnostic is returned to a public caller.
        // A fake/log/array/failover mailer can never claim a real reset dispatch.
        if (! $this->mailConfigured()) {
            throw new ApiException('PASSWORD_RESET_UNAVAILABLE', 'Password-reset email delivery is not configured. Contact your shop administrator.', 503);
        }
        $dispatch = DB::transaction(function () use ($email) {
            $user = User::forEmail($email)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! $user->password_hash) { return null; }
            return [$user->id, $user->email, $this->newToken($user, 'reset', $user->email)];
        }, 3);
        if (! $dispatch) { return; }
        [$userId, $destination, $plain] = $dispatch;
        $url = $this->trustedLink('reset', $plain);
        try {
            // External I/O deliberately occurs after the transaction commits and
            // outside retry callbacks. Never retry an uncertain SMTP submission.
            Mail::mailer(config('mail.default'))->raw("Reset your BiziSmart password:\n".$url."\n\nThis private link expires in 30 minutes. Ignore this email if you did not request it.",
                fn ($message) => $message->to($destination)->subject('Reset your BiziSmart password'));
        } catch (\Throwable) {
            EmailCredentialToken::where('user_id', $userId)->where('token_hash', hash('sha256', $plain))->whereNull('used_at')->update(['used_at' => now()]);
        }
    }

    public function reset(#[\SensitiveParameter] array $input): void
    {
        $this->consume($input['token'], 'reset', function (User $user, EmailCredentialToken $token) use ($input) {
            if (! $user->password_hash || ! $token->email || ! hash_equals(User::emailLookup($user->email), User::emailLookup($token->email))) {
                throw $this->invalidToken();
            }
            $this->setCredentials($user, $user->email, $input['password']);
        });
    }

    public function trustedLink(string $purpose, #[\SensitiveParameter] string $token): string
    {
        if (! in_array($purpose, ['reset', 'activate'], true) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) { throw $this->invalidToken(); }
        $business = Business::findOrFail($this->context->requireId());
        $domain = (string) config('tenancy.domain');
        if (! preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $domain)
            || ! preg_match('/^[a-z0-9][a-z0-9-]*$/D', $business->subdomain)) {
            throw new \LogicException('A trusted tenant domain is required.');
        }
        return 'https://'.$business->subdomain.'.'.$domain.'/#'.$purpose.'='.$token;
    }

    public function mailConfigured(): bool
    {
        return \App\Support\PasswordResetMail::configured();
    }

    private function newToken(User $user, string $purpose, ?string $email = null): string
    {
        EmailCredentialToken::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => now()]);
        $plain = bin2hex(random_bytes(32));
        EmailCredentialToken::create(['user_id' => $user->id, 'purpose' => $purpose, 'email' => $email,
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addMinutes(30)]);
        return $plain;
    }

    private function consume(#[\SensitiveParameter] string $plain, string $purpose, callable $change): void
    {
        DB::transaction(function () use ($plain, $purpose, $change) {
            $snapshot = EmailCredentialToken::where('token_hash', hash('sha256', $plain))->where('purpose', $purpose)->first();
            if (! $snapshot) { throw $this->invalidToken(); }
            $user = User::whereKey($snapshot->user_id)->lockForUpdate()->first();
            $token = EmailCredentialToken::whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || ! $token || $token->used_at || ! $token->expires_at->isFuture()) { throw $this->invalidToken(); }
            $change($user, $token);
            $token->used_at = now();
            $token->save();
        }, 3);
    }

    private function assertEmailAvailable(string $email, ?User $user = null): void
    {
        // Serialize credential assignment per shop, retaining deleted-user email
        // reservations and leaving cross-shop duplicate emails valid.
        Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (User::withTrashed()->forEmail($email)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
            throw new ApiException('VALIDATION_ERROR', 'This email address is already assigned in this shop.', 422, 'email');
        }
    }

    private function setCredentials(User $user, string $email, #[\SensitiveParameter] string $password): void
    {
        $this->assertPassword($password, true);
        $this->assertEmailAvailable($email, $user);
        $user->email = strtolower(trim($email));
        $user->password_hash = $password;
        $user->must_change_pin = false;
        $user->failed_pin_attempts = 0;
        $user->locked_until = null;
        $user->save();
        OfflineLease::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->tokens()->delete();
        AuthChallenge::where('user_id', $user->id)->delete();
        SupervisorApproval::revokeFor($user);
        EmailCredentialToken::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        activity('staff')->performedOn($user)->event('email_credentials_changed')->log('Staff sign-in credentials changed');
    }

    private function assertPassword(#[\SensitiveParameter] string $password, bool $new = false): void
    {
        if (strlen($password) > 72 || str_contains($password, "\0") || ($new && mb_strlen($password) < 12)) {
            throw new ApiException('VALIDATION_ERROR', 'Use a password with at least 12 characters and at most 72 bytes, without null characters.', 422, 'password');
        }
    }

    private function invalidCredentials(): ApiException { return new ApiException('INVALID_CREDENTIALS', 'The email or password is incorrect.', 401); }
    private function locked(): ApiException { return new ApiException('ACCOUNT_LOCKED', 'Please try again after 15 minutes.', 423); }
    private function invalidToken(): ApiException { return new ApiException('INVALID_CREDENTIAL_TOKEN', 'This private link has expired or already been used.', 401); }
}
