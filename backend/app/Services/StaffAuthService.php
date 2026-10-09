<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\StaffResource;
use App\Models\AuthChallenge;
use App\Models\Business;
use App\Models\Device;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

class StaffAuthService
{
    public function __construct(private TenantContext $context, private Google2FA $totp) {}

    public function login(#[\SensitiveParameter] array $input, ?string $ip): array
    {
        // Do the same password-hash work for unknown and known phones.
        $dummyHash = Hash::make(bin2hex(random_bytes(32)));
        return $this->commitResult(function () use ($input, $ip, $dummyHash) {
            $user = User::forPhone($input['phone'])->lockForUpdate()->first();
            $valid = Hash::check($input['pin'], $user?->pin_hash ?? $dummyHash);
            if (! $user || ! $user->is_active) { return $this->invalidPin(); }
            if ($user->locked_until?->isFuture()) { return $this->locked(); }
            if ($user->locked_until !== null) {
                $user->failed_pin_attempts = 0;
                $user->locked_until = null;
            }
            if (! $valid) {
                $user->failed_pin_attempts++;
                if ($user->failed_pin_attempts >= 5) { $user->locked_until = now()->addMinutes(15); }
                $user->save();
                return $user->locked_until ? $this->locked() : $this->invalidPin();
            }
            $user->failed_pin_attempts = 0;
            $user->locked_until = null;
            $user->save();
            if ($user->getRoleNames()->count() !== 1) {
                return new ApiException('STAFF_ROLE_REQUIRED', 'Ask your shop owner to assign your staff role.', 403);
            }
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            $device = Device::where('device_uid', $input['device_uid'])->lockForUpdate()->first();
            if (! $device) {
                $device = Device::create(['device_uid' => $input['device_uid'], 'name' => $input['device_name'], 'user_id' => $user->id]);
                Log::notice('Staff device approval requested.', ['business' => $user->business->publicId(), 'device' => $device->publicId(), 'user' => $user->publicId()]);
            }
            if (! $this->deviceEligible($user, $device)) { return $this->deviceDenied(); }
            $device->last_ip = $ip;
            $device->last_seen_at = now();
            $device->save();
            AuthChallenge::where('user_id', $user->id)->delete();
            $plain = bin2hex(random_bytes(32));
            $challenge = AuthChallenge::create(['user_id' => $user->id, 'device_id' => $device->id,
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addMinutes(5)]);
            return $this->advance($user, $device, $challenge, $plain);
        });
    }

    public function verifyTotp(#[\SensitiveParameter] string $plain, #[\SensitiveParameter] string $code): array
    {
        return $this->withChallenge($plain, function (User $user, Device $device, AuthChallenge $challenge) use ($plain, $code) {
            if (! $user->hasRole('owner') || $challenge->two_factor_verified) { return $this->invalidChallenge(); }
            $secret = $user->totp_confirmed_at ? $user->totp_secret : $challenge->setup_secret;
            if (! $secret) { return $this->invalidChallenge(); }
            // The library returns a boolean with a null previous step; use a sentinel to obtain the actual accepted step.
            $step = $this->totp->verifyKeyNewer($secret, $code, $user->totp_last_step ?? 0, 1, intdiv(now()->timestamp, 30));
            if ($step === false) {
                $challenge->attempts++;
                if ($challenge->attempts >= 5) { $challenge->delete(); } else { $challenge->save(); }
                return new ApiException('INVALID_TOTP', 'The authenticator code is invalid or has already been used.', 401);
            }
            $user->totp_secret = $secret;
            $user->totp_confirmed_at ??= now();
            $user->totp_last_step = $step;
            $user->save();
            $challenge->two_factor_verified = true;
            $challenge->setup_secret = null;
            $challenge->save();
            return $this->advance($user, $device, $challenge, $plain);
        });
    }

    public function changePin(#[\SensitiveParameter] string $plain, #[\SensitiveParameter] string $newPin): array
    {
        return $this->withChallenge($plain, function (User $user, Device $device, AuthChallenge $challenge) use ($plain, $newPin) {
            if (! $user->must_change_pin || ($user->hasRole('owner') && ! $challenge->two_factor_verified)) {
                return $this->invalidChallenge();
            }
            if (Hash::check($newPin, $user->pin_hash)) {
                return new ApiException('VALIDATION_ERROR', 'Choose a different PIN.', 422, 'new_pin');
            }
            $user->pin_hash = $newPin;
            $user->must_change_pin = false;
            $user->save();
            $user->tokens()->delete();
            AuthChallenge::where('user_id', $user->id)->whereKeyNot($challenge->id)->delete();
            return $this->advance($user, $device, $challenge, $plain);
        });
    }

    public function resetPin(User $actor, User $target, #[\SensitiveParameter] string $newPin): void
    {
        $actor->assertCurrentBusiness();
        $target->assertCurrentBusiness();
        if (! $actor->is_active || ! $actor->hasRole('owner')) { throw new \Illuminate\Auth\Access\AuthorizationException; }
        DB::transaction(function () use ($actor, $target, $newPin) {
            $target->refreshForUpdate();
            $target->pin_hash = $newPin;
            $target->must_change_pin = true;
            $target->failed_pin_attempts = 0;
            $target->locked_until = null;
            $target->save();
            $target->tokens()->delete();
            AuthChallenge::where('user_id', $target->id)->delete();
            \App\Models\SupervisorApproval::revokeFor($target);
            activity('staff')->performedOn($target)->causedBy($actor)->event('pin_reset')
                ->withProperties(['request_id' => request()->attributes->get('request_id')])->log('Staff PIN reset');
        });
    }

    public function rotate(User $user): array
    {
        return $this->commitResult(function () use ($user) {
            $user->refreshForUpdate();
            $current = $user->currentAccessToken();
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            // A request authenticated before a concurrent rotation/reset must not mint another token.
            if (! $current || ! $user->tokens()->whereKey($current->id)->exists()
                || ! $user->is_active || $user->must_change_pin) { return $this->invalidChallenge(); }
            $device = Device::whereKey($current->device_id)->lockForUpdate()->first();
            if (! $device || $device->status !== 'approved') { return $this->deviceDenied(); }
            $current->delete();
            return $this->issue($user, $device);
        });
    }

    private function withChallenge(string $plain, callable $action): array
    {
        return $this->commitResult(function () use ($plain, $action) {
            $snapshot = AuthChallenge::where('token_hash', hash('sha256', $plain))->first();
            if (! $snapshot) { return $this->invalidChallenge(); }
            $user = User::whereKey($snapshot->user_id)->lockForUpdate()->first();
            if (! $user || ! $user->is_active || $user->locked_until?->isFuture()) { return $this->invalidChallenge(); }
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($snapshot->device_id)->lockForUpdate()->first();
            $challenge = AuthChallenge::whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $challenge || ! $challenge->expires_at->isFuture()) { return $this->invalidChallenge(); }
            if (! $device || ! $this->deviceEligible($user, $device)) { return $this->deviceDenied(); }
            if ($user->getRoleNames()->count() !== 1) { return $this->invalidChallenge(); }
            return $action($user, $device, $challenge);
        });
    }

    private function advance(User $user, Device $device, AuthChallenge $challenge, string $plain): array
    {
        $owner = $user->hasRole('owner');
        $step = $owner && ! $challenge->two_factor_verified ? ($user->totp_confirmed_at ? 'verify_totp' : 'enroll_totp')
            : ($user->must_change_pin ? 'change_pin' : null);
        if ($step) {
            $data = ['challenge_token' => $plain, 'next_step' => $step, 'expires_at' => $challenge->expires_at->toISOString()];
            if ($step === 'enroll_totp') {
                $challenge->setup_secret ??= $this->totp->generateSecretKey();
                $challenge->save();
                $data['setup_secret'] = $challenge->setup_secret;
                $data['otpauth_uri'] = $this->totp->getQRCodeUrl('BiziSmart', $user->business->subdomain.':'.$user->publicId(), $challenge->setup_secret);
            }
            return ['status' => 202, 'data' => $data];
        }
        if ($device->status !== 'approved') {
            // deviceEligible was evaluated while the business/device were locked.
            $device->status = 'approved';
            $device->approved_by = $user->id;
            $device->save();
        }
        $challenge->delete();
        return $this->issue($user, $device);
    }

    private function issue(User $user, Device $device): array
    {
        $token = $user->createToken('device:'.$device->publicId(), ['*'], now()->addDays(30));
        $token->accessToken->device_id = $device->id;
        $token->accessToken->save();
        $user->last_login_at = now();
        $user->save();
        return ['status' => 200, 'data' => ['token' => $token->plainTextToken, 'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toISOString(), 'user' => (new StaffResource($user))->resolve()]];
    }

    private function deviceEligible(User $user, Device $device): bool
    {
        return $device->status === 'approved' || ($device->status === 'pending' && $user->hasRole('owner')
            && (int) $device->user_id === (int) $user->id && ! Device::where('status', 'approved')->exists());
    }

    private function commitResult(callable $action): array
    {
        $result = DB::transaction($action, 3);
        if ($result instanceof ApiException) { throw $result; }
        return $result;
    }

    private function invalidPin(): ApiException { return new ApiException('INVALID_PIN', 'The phone or PIN is incorrect.', 401); }
    private function locked(): ApiException { return new ApiException('ACCOUNT_LOCKED', 'Please try again after 15 minutes.', 423); }
    private function invalidChallenge(): ApiException { return new ApiException('INVALID_CHALLENGE', 'Please start sign-in again.', 401); }
    private function deviceDenied(): ApiException { return new ApiException('DEVICE_NOT_APPROVED', 'Ask your shop owner to approve this device.', 403); }
}
