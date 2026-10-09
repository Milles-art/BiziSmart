<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\SupervisorApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class SupervisorApprovalService
{
    public const PERMISSIONS = ['refund', 'void', 'give_discount', 'approve_variance'];

    public function __construct(private Google2FA $totp) {}

    public function issue(User $requester, #[\SensitiveParameter] array $input): array
    {
        $requester->assertCurrentBusiness();
        // Same hash work for unknown or disabled accounts; PIN failures commit even when approval fails.
        $dummy = Hash::make(bin2hex(random_bytes(32)));
        $result = DB::transaction(function () use ($requester, $input, $dummy) {
            $candidate = User::forPhone($input['phone'])->first();
            $users = $this->lockUsers($requester->id, $candidate?->id);
            $actor = $users->get($requester->id);
            $session = $this->session($requester, $actor, $input['permission']);
            $approver = $candidate ? $users->get($candidate->id) : null;
            $valid = Hash::check($input['pin'], $approver?->pin_hash ?? $dummy);
            $valid = $valid && (! $approver || hash_equals($approver->phone_lookup, User::phoneLookup($input['phone'])));
            if (! $approver || ! $approver->is_active) { return $this->invalidPin(); }
            if ($approver->locked_until?->isFuture()) { return $this->locked(); }
            if ($approver->locked_until !== null) { $approver->failed_pin_attempts = 0; $approver->locked_until = null; }
            if (! $valid) {
                $approver->failed_pin_attempts++;
                if ($approver->failed_pin_attempts >= 5) { $approver->locked_until = now()->addMinutes(15); }
                $approver->save();
                return $approver->locked_until ? $this->locked() : $this->invalidPin();
            }
            $approver->failed_pin_attempts = 0;
            $approver->locked_until = null;
            $approver->save();
            if (! $this->eligible($approver, $input['permission'])) {
                return new ApiException('APPROVER_NOT_AUTHORIZED', 'An eligible owner or manager must approve this action.', 403);
            }
            if ($approver->hasRole('owner')) {
                if (! isset($input['code'])) { return new ApiException('TOTP_REQUIRED', 'Enter the owner’s authenticator code to approve.', 403); }
                $step = $this->totp->verifyKeyNewer($approver->totp_secret, $input['code'], $approver->totp_last_step ?? 0, 1, intdiv(now()->timestamp, 30));
                if ($step === false) { return new ApiException('INVALID_TOTP', 'The authenticator code is invalid or has already been used.', 401); }
                $approver->totp_last_step = $step;
                $approver->save();
            }
            $operation = strtolower($input['operation_id']);
            SupervisorApproval::where('requester_token_id', $session->id)->where('operation_id', $operation)
                ->where('permission', $input['permission'])->whereNull('consumed_at')->delete();
            $plain = bin2hex(random_bytes(32));
            $approval = SupervisorApproval::create(['requester_id' => $actor->id, 'approver_id' => $approver->id,
                'requester_token_id' => $session->id, 'device_id' => $session->device_id, 'permission' => $input['permission'],
                'operation_id' => $operation, 'payload_hash' => $input['payload_hash'], 'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addMinutes(5)]);
            activity('staff')->performedOn($approval)->causedBy($approver)->event('supervisor_approval_issued')
                ->withProperties(['permission' => $approval->permission, 'requester' => $actor->publicId(),
                    'approval' => $approval->publicId(), 'operation_id' => $approval->operation_id])->log('Supervisor approval issued');
            return ['approved_by' => $approver->publicId(), 'approval_token' => $plain, 'expires_at' => $approval->expires_at->toISOString()];
        }, 3);
        if ($result instanceof ApiException) { throw $result; }
        return $result;
    }

    /** Domain services must supply a server-validated snapshot and perform DB changes inside this callback. */
    public function execute(User $requester, #[\SensitiveParameter] string $plain, string $permission, string $operationId,
        array $serverSnapshot, callable $operation): mixed
    {
        $requester->assertCurrentBusiness();
        return DB::transaction(function () use ($requester, $plain, $permission, $operationId, $serverSnapshot, $operation) {
            $snapshot = SupervisorApproval::where('token_hash', hash('sha256', $plain))->first();
            if (! $snapshot) { throw $this->invalidApproval(); }
            $users = $this->lockUsers($requester->id, $snapshot->approver_id);
            $actor = $users->get($requester->id);
            $session = $this->session($requester, $actor, $permission);
            $approver = $users->get($snapshot->approver_id);
            $approval = SupervisorApproval::whereKey($snapshot->id)->lockForUpdate()->first();
            if (! $approval || $approval->consumed_at || ! $approval->expires_at->isFuture()
                || (int) $approval->requester_id !== (int) $actor->id || (int) $approval->requester_token_id !== (int) $session->id
                || (int) $approval->device_id !== (int) $session->device_id || $approval->permission !== $permission
                || $approval->operation_id !== strtolower($operationId) || ! hash_equals($approval->payload_hash, self::snapshotHash($serverSnapshot))
                || ! $approver || ! $this->eligible($approver, $permission) || $approver->locked_until?->isFuture()) {
                throw $this->invalidApproval();
            }
            $approval->consumed_at = now();
            $approval->save();
            $result = $operation($approver);
            activity('staff')->performedOn($approval)->causedBy($approver)->event('supervisor_approval_consumed')
                ->withProperties(['permission' => $permission, 'requester' => $actor->publicId(),
                    'approval' => $approval->publicId(), 'operation_id' => $approval->operation_id])->log('Supervisor approval consumed');
            return $result;
        }, 3);
    }

    public static function snapshotHash(array $snapshot): string
    {
        $normalize = function ($value) use (&$normalize) {
            if (is_array($value)) {
                if (! array_is_list($value)) { ksort($value, SORT_STRING); }
                return array_map($normalize, $value);
            }
            if (is_float($value) || is_object($value) || is_resource($value)) {
                throw new \InvalidArgumentException('Approval snapshots must use exact integers or strings, never floating point values.');
            }
            return $value;
        };
        return hash('sha256', json_encode($normalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function lockUsers(int $requesterId, ?int $approverId): \Illuminate\Support\Collection
    {
        return User::whereIn('id', array_unique(array_filter([$requesterId, $approverId])))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function session(User $original, ?User $fresh, string $permission): PersonalAccessToken
    {
        if (! in_array($permission, self::PERMISSIONS, true)) { throw new ApiException('VALIDATION_ERROR', 'Unsupported approval permission.', 422, 'permission'); }
        $token = $original->currentAccessToken();
        if (! $fresh || ! $fresh->is_active || $fresh->must_change_pin || $fresh->getRoleNames()->count() !== 1
            || ($fresh->hasRole('owner') && ! $fresh->totp_confirmed_at)) { throw new \Illuminate\Auth\AuthenticationException; }
        $current = $token ? PersonalAccessToken::whereKey($token->id)->where('tokenable_id', $fresh->id)->lockForUpdate()->first() : null;
        if (! $current || ($current->expires_at && ! $current->expires_at->isFuture()) || ! $current->device_id
            || ! Device::whereKey($current->device_id)->where('status', 'approved')->lockForUpdate()->first()) {
            throw new \Illuminate\Auth\AuthenticationException;
        }
        if (! $fresh->hasPermissionTo($permission === 'approve_variance' ? 'manage_till' : 'sell')) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        return $current;
    }

    private function eligible(User $user, string $permission): bool
    {
        return $user->is_active && ! $user->must_change_pin && $user->getRoleNames()->count() === 1
            && ($user->hasRole('manager') || ($user->hasRole('owner') && $user->totp_confirmed_at && $user->totp_secret))
            && $user->hasPermissionTo($permission);
    }
    private function invalidApproval(): ApiException { return new ApiException('INVALID_APPROVAL', 'Request a fresh approval for this exact action.', 403); }
    private function invalidPin(): ApiException { return new ApiException('INVALID_PIN', 'The phone or PIN is incorrect.', 401); }
    private function locked(): ApiException { return new ApiException('ACCOUNT_LOCKED', 'Please try again after 15 minutes.', 423); }
}
