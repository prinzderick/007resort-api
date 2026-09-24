<?php

namespace App\Domain\Ticketing\Services;

/**
 * Unguessable, signed, non-sequential QR tokens: `R7.<24 chars of 18 random bytes>.<12 chars of HMAC-SHA256>`.
 * The signature is checked BEFORE any DB lookup, so garbage / enumeration attempts never reach MySQL and can't be
 * told apart from unknown tokens (both -> 404 ticket_invalid). The DB `qr_token` UNIQUE column is the lookup key.
 * Signing key: config('ticketing.qr_key') (env TICKET_QR_KEY) else derived from APP_KEY. Local and Cloud nodes must
 * share the key if either must verify tokens issued by the other.
 */
final class QrTokens
{
    private const PREFIX = 'R7';

    public function generate(): string
    {
        $body = $this->b64(random_bytes(18));

        return self::PREFIX.'.'.$body.'.'.$this->sign($body);
    }

    public function isWellFormed(string $token): bool
    {
        if (strlen($token) > 64 || ! preg_match('/^R7\.([A-Za-z0-9_-]{24})\.([A-Za-z0-9_-]{12})$/', $token, $m)) {
            return false;
        }

        return hash_equals($this->sign($m[1]), $m[2]);
    }

    private function sign(string $body): string
    {
        $key = config('ticketing.qr_key') ?: hash_hmac('sha256', 'r007.ticket.qr.v1', (string) config('app.key'));

        return substr($this->b64(hash_hmac('sha256', $body, $key, true)), 0, 12);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
