<?php

namespace App\Domain\Sync\Services;

final class PeerResponse
{
    /** @param array<string, mixed> $body decoded JSON ([] when not JSON) */
    public function __construct(public readonly int $status, public readonly array $body = [], public readonly ?int $retryAfter = null) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function problemCode(): ?string
    {
        return isset($this->body['code']) ? (string) $this->body['code'] : null;
    }
}
