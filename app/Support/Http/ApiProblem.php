<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * Throw from anywhere to produce an RFC 7807 `application/problem+json` response with a stable,
 * machine-readable `code` (snake_case; never rename a shipped code — clients switch on it).
 *
 *   throw ApiProblem::conflict('slot_already_taken', 'That slot was just booked.');
 */
class ApiProblem extends RuntimeException
{
    /** @param array<string, mixed> $extensions extra top-level members (camelCase keys) */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $detail = '',
        public readonly ?string $title = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail !== '' ? $detail : ($title ?? $problemCode));
    }

    public static function badRequest(string $code, string $detail = '', array $extensions = []): self
    {
        return new self(400, $code, $detail, 'Bad request', $extensions);
    }

    /** @param string $code unauthenticated | token_expired | invalid_credentials | ... */
    public static function unauthenticated(string $code = 'unauthenticated', string $detail = 'Authentication is required.'): self
    {
        return new self(401, $code, $detail, 'Unauthenticated', [], ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $code = 'permission_denied', string $detail = 'You are not allowed to perform this action.', array $extensions = []): self
    {
        return new self(403, $code, $detail, 'Forbidden', $extensions);
    }

    public static function permissionDenied(string $permission): self
    {
        return new self(403, 'permission_denied', "Missing permission: {$permission}.", 'Forbidden', ['permission' => $permission, 'meta' => ['permission' => $permission]]);
    }

    public static function notFound(string $code = 'not_found', string $detail = 'The requested resource was not found.'): self
    {
        return new self(404, $code, $detail, 'Not found');
    }

    public static function conflict(string $code, string $detail = '', array $extensions = []): self
    {
        return new self(409, $code, $detail, 'Conflict', $extensions);
    }

    /** Account locked (contract: 403 `account_locked`). */
    public static function locked(string $code, string $detail = '', array $extensions = []): self
    {
        return new self(403, $code, $detail, 'Forbidden', $extensions);
    }

    /** Optimistic-concurrency failure (If-Match mismatch = 412, missing = 428, duplicate client id = 409). */
    public static function concurrencyConflict(string $detail = 'The resource was modified by someone else.', int $status = 412): self
    {
        return new self($status, 'concurrency_conflict', $detail, 'Precondition failed');
    }

    /** @param array<string, list<string>> $errors field => messages */
    public static function unprocessable(string $code, string $detail = '', array $errors = []): self
    {
        return new self(422, $code, $detail, 'Unprocessable entity', $errors === [] ? [] : ['errors' => $errors]);
    }

    public static function tooManyRequests(string $detail = 'Too many requests.', ?int $retryAfter = null): self
    {
        return new self(429, 'rate_limited', $detail, 'Too many requests', [], $retryAfter ? ['Retry-After' => (string) $retryAfter] : []);
    }
}
