<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/** Social sign-in test helpers: enabled providers, service-token headers, and a locally-signed fake Google JWKS. */
trait SocialHelpers
{
    protected const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    protected const CLIENT_ID = 'test-client.apps.example.test';

    /** @var array{pem: string, jwk: array<string, string>} */
    protected array $googleKey;

    /** @var array<string, mixed> */
    protected array $jwksBody = [];

    protected function enableSocial(): void
    {
        config(['customer.social.enabled' => ['google', 'facebook'], 'customer.social.trusted_email_providers' => ['google'], 'customer.social.google.client_ids' => [self::CLIENT_ID]]);
    }

    /** @return array<string, string> */
    protected function svc(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? 'r7s_dev_booking_web'), 'Accept' => 'application/json'];
    }

    /** @return array<string, mixed> claims-mode request */
    protected function claims(array $over = []): array
    {
        return $over + ['provider' => 'google', 'providerUserId' => 'g-'.bin2hex(random_bytes(6)), 'email' => 'ada.social@example.test', 'emailVerified' => true, 'name' => 'Ada Social', 'termsAccepted' => true];
    }

    protected function socialLogin(array $body, ?string $token = null)
    {
        return $this->postJson('/api/v1/public/customers/social/login', $body, $this->svc($token));
    }

    /** Fake Google JWKS (RSA key generated in the test) served through Http::fake. */
    protected function fakeGoogle(string $kid = 'kid-1', array $extraKeys = []): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->googleKey = ['pem' => $pem, 'kid' => $kid, 'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]];
        $this->jwksBody = ['keys' => [$this->googleKey['jwk'], ...$extraKeys]];
        Http::fake([self::JWKS_URL => fn () => Http::response($this->jwksBody, 200, ['Cache-Control' => 'public, max-age=600'])]);
    }

    /** Google publishes a new key (the previous one may stay listed). */
    protected function rotateGoogle(string $kid, array $extraKeys = []): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $d = openssl_pkey_get_details($res);
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->googleKey = ['pem' => $pem, 'kid' => $kid, 'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]];
        $this->jwksBody = ['keys' => [$this->googleKey['jwk'], ...$extraKeys]];
    }

    /** Sign a Google-style id token with the fake key (or an attacker's key when $pem is given). */
    protected function idToken(array $over = [], ?string $pem = null, ?string $kid = null): string
    {
        $claims = $over + [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'sub' => 'gsub-1', 'email' => 'ada.social@example.test', 'email_verified' => true,
            'name' => 'Ada Social', 'given_name' => 'Ada', 'family_name' => 'Social', 'picture' => 'https://lh3.example.test/a.png', 'iat' => time() - 10, 'exp' => time() + 3600,
        ];

        return JWT::encode(array_filter($claims, fn ($v) => $v !== null), $pem ?? $this->googleKey['pem'], 'RS256', $kid ?? $this->googleKey['kid']);
    }
}
