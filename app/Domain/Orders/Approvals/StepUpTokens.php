<?php

namespace App\Domain\Orders\Approvals;

use Illuminate\Support\Facades\Cache;

/**
 * Single-use, short-lived `X-Step-Up-Token` issued when a supervisor authenticates on someone else's device
 * (contract: POST /auth/staff/step-up). Stored server-side as sha256(token) -> {staffId, permission, entityId}; consumed on use.
 * Identity's step-up endpoint calls issue(); the orders/approvals code calls consume().
 */
final class StepUpTokens
{
    public const TTL_SECONDS = 120;

    public static function issue(string $approverStaffId, string $permission, ?string $entityType = null, ?string $entityId = null, int $ttl = self::TTL_SECONDS): string
    {
        $token = 'r7s_'.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Cache::put(self::key($token), ['staffId' => $approverStaffId, 'permission' => $permission, 'entityType' => $entityType, 'entityId' => $entityId], $ttl);

        return $token;
    }

    /** Returns the approver's staff id and burns the token, or null when unknown/expired/for another permission or entity. */
    public static function consume(string $token, string $permission, ?string $entityId = null): ?string
    {
        $data = Cache::get(self::key($token));
        if (! is_array($data) || $data['permission'] !== $permission) {
            return null;
        }
        if (($data['entityId'] ?? null) !== null && $entityId !== null && strtolower($data['entityId']) !== strtolower($entityId)) {
            return null;
        }
        Cache::forget(self::key($token));

        return $data['staffId'];
    }

    private static function key(string $token): string
    {
        return 'stepup-token:'.hash('sha256', $token);
    }
}
