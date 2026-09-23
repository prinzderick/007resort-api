<?php

namespace App\Domain\Identity\Services;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Server-side "sign out everywhere" for an account (used on credential reset, suspension, termination, device revoke). */
class SessionRevoker
{
    /** @return int number of sessions revoked */
    public function revokeAccount(string $userAccountId, string $reason): int
    {
        return DB::table('session')->where('user_account_id', Ids::toBinary($userAccountId))->whereNull('revoked_at')
            ->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'revoked_reason' => $reason]);
    }

    /** @return int number of sessions revoked */
    public function revokeDevice(string $deviceId, string $reason): int
    {
        return DB::table('session')->where('device_id', Ids::toBinary($deviceId))->whereNull('revoked_at')
            ->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'revoked_reason' => $reason]);
    }
}
