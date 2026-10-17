<?php
namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\Business;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Owner-managed headless integration credentials. Tokens carry only the
 * enterprise:read ability, are bound to the issuing owner and business, and
 * expose read-only enterprise routes. No financial mutation is reachable.
 * Plaintext is returned once at issuance and never stored or listed.
 */
class EnterpriseService
{
    public const ABILITY = 'enterprise:read';

    public function __construct(private TenantContext $context,private FinancialActorService $actors) {}

    private function owner(User $original): User
    {
        $actor = $this->actors->lockSession($original);
        if (! $actor->hasRole('owner') || (array) $actor->currentAccessToken()->abilities !== ['*']) {
            throw new AuthorizationException;
        }
        Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
        return $actor;
    }

    public function issue(User $original, array $input): array
    {
        return DB::transaction(function () use ($original, $input) {
            $actor = $this->owner($original);
            $days = (int) ($input['expires_in_days'] ?? 90);
            $result = $actor->createToken(trim($input['name']), [self::ABILITY], now()->addDays($days));
            $token = $result->accessToken;
            activity('integrations')->performedOn($token)->causedBy($actor)->event('enterprise_token_issued')
                ->withProperties(['token_id' => $token->publicId(), 'name' => $token->name])->log('Enterprise token issued');
            return ['id' => $token->publicId(), 'name' => $token->name, 'abilities' => $token->abilities,
                'token' => $result->plainTextToken, 'expires_at' => $token->expires_at->toISOString()];
        }, 3);
    }

    public function index(User $original): array
    {
        return DB::transaction(function () use ($original) {
            $this->owner($original);
            $tokens = PersonalAccessToken::whereJsonContains('abilities', self::ABILITY)->orderByDesc('id')->get();
            return ['tokens' => $tokens->map(fn (PersonalAccessToken $token) => $this->view($token))->all()];
        }, 3);
    }

    public function revoke(User $original, PersonalAccessToken $token): array
    {
        return DB::transaction(function () use ($original, $token) {
            $actor = $this->owner($original);
            $token->refreshForUpdate();
            if (! in_array(self::ABILITY, (array) $token->abilities, true)) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            }
            $view = $this->view($token);
            $token->delete();
            activity('integrations')->causedBy($actor)->event('enterprise_token_revoked')
                ->withProperties(['token_id' => $view['id'], 'name' => $view['name']])->log('Enterprise token revoked');
            return ['revoked' => true, 'id' => $view['id']];
        }, 3);
    }

    private function view(PersonalAccessToken $token): array
    {
        return ['id' => $token->publicId(), 'name' => $token->name, 'abilities' => array_values((array) $token->abilities),
            'expires_at' => $token->expires_at?->toISOString(), 'last_used_at' => $token->last_used_at?->toISOString(),
            'created_at' => $token->created_at->toISOString()];
    }
}
