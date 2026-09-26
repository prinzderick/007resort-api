<?php

namespace App\Domain\Guest\Services;

use App\Domain\Guest\Support\GuestSession;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** `r7o_` order access tokens: 256-bit, SHA-256 at rest, scoped to ONE guest order, several may be active (max `guest.max_active_tokens`). */
class GuestAccess
{
    public const PREFIX = 'r7o_';

    private const FMT = 'Y-m-d H:i:s.u';

    /** @return array{token: string, expiresAt: string} plaintext is returned ONCE */
    public function mint(string $guestOrderId): array
    {
        $token = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = CarbonImmutable::now('UTC')->addDays((int) config('guest.access_ttl_days'));
        $order = Ids::toBinary($guestOrderId);
        DB::table('guest_access_token')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'guest_order_id' => $order, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires->format(self::FMT),
        ]);
        $keep = DB::table('guest_access_token')->where('guest_order_id', $order)->whereNull('revoked_at')->orderByDesc('created_at')->orderByDesc('id')
            ->limit((int) config('guest.max_active_tokens'))->pluck('id')->all();
        DB::table('guest_access_token')->where('guest_order_id', $order)->whereNull('revoked_at')->whereNotIn('id', $keep)->update(['revoked_at' => now('UTC')->format(self::FMT)]);

        return ['token' => $token, 'expiresAt' => $expires->format('Y-m-d\TH:i:s.v\Z')];
    }

    /** @return GuestSession|'expired'|null */
    public function resolve(string $token): GuestSession|string|null
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }
        $row = DB::table('guest_access_token as t')->join('guest_order as o', 'o.id', '=', 't.guest_order_id')
            ->where('t.token_hash', hash('sha256', $token))->whereNull('t.revoked_at')->whereNull('o.erased_at')
            ->first(['t.id as token_id', 't.expires_at', 'o.*']);
        if ($row === null) {
            return null;
        }
        if (CarbonImmutable::parse($row->expires_at, 'UTC')->lte(CarbonImmutable::now('UTC'))) {
            return 'expired';
        }

        return self::session($row);
    }

    public static function session(object $o): GuestSession
    {
        $u = fn ($b) => $b === null ? null : Ids::fromBinary($b);

        return new GuestSession(Ids::fromBinary($o->id), Ids::fromBinary($o->organization_id), $o->reference, $o->kind, $u($o->booking_id), $u($o->order_id), $u($o->membership_id),
            $o->contact_name, $o->contact_email, $o->contact_phone);
    }
}
