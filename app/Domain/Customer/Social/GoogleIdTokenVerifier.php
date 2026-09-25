<?php

namespace App\Domain\Customer\Social;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side verification of a Google OpenID Connect id token (RS256 only): signature against Google's JWKS (cached per Cache-Control,
 * one forced refetch per minute when an unknown `kid` shows up = key rotation), `iss`, `aud` = configured client id(s), `exp`/`nbf`
 * (60 s leeway), `sub`, optional `nonce`. Returns normalised claims. The token itself is never logged or stored.
 */
class GoogleIdTokenVerifier
{
    private const KEY = 'social:google:jwks';

    private const COOLDOWN_KEY = 'social:google:jwks:refetch';

    public function configured(): bool
    {
        return config('customer.social.google.client_ids') !== [];
    }

    /** @return array{sub: string, email: ?string, emailVerified: bool, name: ?string, givenName: ?string, familyName: ?string, avatarUrl: ?string} */
    public function verify(string $idToken, ?string $nonce = null): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || strlen($idToken) > 8192) {
            throw new IdTokenException('malformed');
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid']) || ! is_string($header['kid'])) {
            throw new IdTokenException('malformed');
        }

        $keys = $this->keys($header['kid']);
        $previous = JWT::$leeway;
        JWT::$leeway = 60;
        try {
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (SignatureInvalidException) {
            throw new IdTokenException('bad_signature');
        } catch (ExpiredException) {
            throw new IdTokenException('expired');
        } catch (BeforeValidException) {
            throw new IdTokenException('not_yet_valid');
        } catch (Throwable) {
            throw new IdTokenException('malformed');
        } finally {
            JWT::$leeway = $previous;
        }

        if (! in_array($claims['iss'] ?? null, (array) config('customer.social.google.issuers'), true)) {
            throw new IdTokenException('wrong_issuer');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (array_intersect($aud, (array) config('customer.social.google.client_ids')) === []) {
            throw new IdTokenException('wrong_audience');
        }
        if (empty($claims['exp'])) {
            throw new IdTokenException('malformed');
        }
        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 191) {
            throw new IdTokenException('malformed');
        }
        if ($nonce !== null && $nonce !== '' && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new IdTokenException('nonce_mismatch');
        }
        $ev = $claims['email_verified'] ?? false;
        $email = isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== '' ? mb_strtolower(trim($claims['email'])) : null;

        return [
            'sub' => $sub, 'email' => $email, 'emailVerified' => $email !== null && ($ev === true || $ev === 'true'),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null, 'givenName' => isset($claims['given_name']) ? (string) $claims['given_name'] : null,
            'familyName' => isset($claims['family_name']) ? (string) $claims['family_name'] : null, 'avatarUrl' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /** @return array<string, Key> */
    private function keys(string $kid): array
    {
        $jwks = Cache::get(self::KEY);
        if (! is_array($jwks) || ! $this->hasKid($jwks, $kid)) {
            // Cold cache or unknown kid (rotation): refetch, but at most once a minute so a forged kid cannot make us hammer Google.
            if ($jwks === null || Cache::add(self::COOLDOWN_KEY, 1, 60)) {
                $jwks = $this->fetch() ?? (is_array($jwks) ? $jwks : throw new IdTokenException('jwks_unavailable'));
            }
        }
        if (! $this->hasKid($jwks, $kid)) {
            throw new IdTokenException('unknown_key');
        }

        try {
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable) {
            throw new IdTokenException('jwks_unavailable');
        }
    }

    private function hasKid(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] ?? [] as $k) {
            if (($k['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    /** @return ?array<string, mixed> */
    private function fetch(): ?array
    {
        try {
            $res = Http::timeout(5)->acceptJson()->get((string) config('customer.social.google.jwks_url'));
        } catch (Throwable) {
            return null;
        }
        $jwks = $res->successful() ? $res->json() : null;
        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) {
            return null;
        }
        $ttl = 3600;
        if (preg_match('/max-age=(\d+)/', (string) $res->header('Cache-Control'), $m)) {
            $ttl = max(300, min(86400, (int) $m[1]));
        }
        Cache::put(self::KEY, $jwks, $ttl);

        return $jwks;
    }
}
