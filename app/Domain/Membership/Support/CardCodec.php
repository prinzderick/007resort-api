<?php

namespace App\Domain\Membership\Support;

/**
 * Member card identifiers.
 *  - QR token: `M1.<membership uuid without dashes>.<20 hex chars of HMAC-SHA256(app key, "membership-card|<uuid>")>`.
 *    Tamper-proof without a DB round trip; the member_card row is still authoritative (revocable).
 *  - NFC uid: upper-case hex, separators stripped.
 *  - Membership number: `M` + 9 Crockford base32 chars (no I/L/O/U), e.g. M7K3Q9X2A.
 */
final class CardCodec
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function qrToken(string $membershipId): string
    {
        return 'M1.'.str_replace('-', '', $membershipId).'.'.self::mac($membershipId);
    }

    /** @return string|null membership uuid when the token is well-formed and correctly signed */
    public static function verifyQr(string $token): ?string
    {
        if (! preg_match('/^M1\.([0-9a-f]{32})\.([0-9a-f]{20})$/', $token, $m)) {
            return null;
        }
        $h = $m[1];
        $uuid = substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);

        return hash_equals(self::mac($uuid), $m[2]) ? $uuid : null;
    }

    public static function normalizeNfc(string $uid): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $uid));
    }

    public static function newNumber(): string
    {
        $s = 'M';
        for ($i = 0; $i < 9; $i++) {
            $s .= self::ALPHABET[random_int(0, 31)];
        }

        return $s;
    }

    public static function normalizeNumber(string $n): string
    {
        return strtoupper(trim($n));
    }

    private static function mac(string $uuid): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required to sign member cards.');
        }

        return substr(hash_hmac('sha256', 'membership-card|'.strtolower($uuid), $key), 0, 20);
    }
}
