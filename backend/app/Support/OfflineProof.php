<?php
namespace App\Support;

use App\Services\SupervisorApprovalService;

final class OfflineProof
{
    public static function sign(array $body, #[\SensitiveParameter] string $secret): string {
        return hash_hmac('sha256', SupervisorApprovalService::snapshotHash($body), $secret);
    }
    public static function verify(array $body, #[\SensitiveParameter] string $secret, string $signature): bool {
        return preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1 && hash_equals(self::sign($body, $secret), $signature);
    }
    public static function snapshot(string $leaseId, array $snapshot): string {
        return self::sign(['lease_id'=>$leaseId, 'snapshot'=>$snapshot], config('app.key'));
    }
}
